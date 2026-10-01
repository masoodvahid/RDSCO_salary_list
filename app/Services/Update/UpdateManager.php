<?php

namespace App\Services\Update;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

/**
 * In-app update from a GitHub release, in steps that run as separate HTTP requests, so the
 * last step (migrations) always runs with the freshly installed code:
 *
 *   download  → fetch tukahr-<v>.zip, verify its SHA-256, unpack to a staging folder, check PHP
 *   backup    → database dump (no shell needed)
 *   install   → maintenance mode, swap code folders (old ones kept), copy public files
 *   finalize  → migrate --force, rebuild caches, leave maintenance mode
 *   rollback  → restore the database dump and the previous code folders
 *
 * .env and storage/ are never touched. State lives in storage/app/updates/state.json.
 */
class UpdateManager
{
    public const STEPS = ['download', 'backup', 'install', 'finalize', 'rollback'];

    /** Top-level names a package may replace (anything else in the package is ignored). */
    private const ALLOWED = ['app', 'bootstrap', 'config', 'database', 'lang', 'resources', 'routes', 'vendor',
        'artisan', 'composer.json', 'composer.lock', 'VERSION', 'release.json'];

    private const REQUIRED = ['artisan', 'VERSION', 'release.json', 'vendor/autoload.php', 'bootstrap/app.php', 'public/index.php'];

    private const MAX_UNPACKED_BYTES = 600 * 1024 * 1024;

    protected string $basePath;

    protected string $publicPath;

    protected string $workPath;

    protected Filesystem $files;

    public function __construct(
        protected ReleaseChecker $releases,
        protected DatabaseBackup $backups,
        ?string $basePath = null,
        ?string $publicPath = null,
        ?string $workPath = null,
    ) {
        $this->basePath = rtrim($basePath ?? base_path(), '/');
        $this->publicPath = rtrim($publicPath ?? public_path(), '/');
        $this->workPath = rtrim($workPath ?? storage_path('app/updates'), '/');
        $this->files = new Filesystem;
    }

    public function currentVersion(): string
    {
        return trim((string) @file_get_contents($this->basePath.'/VERSION')) ?: 'dev';
    }

    public function check(): ?Release
    {
        return $this->releases->latest();
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        $file = $this->workPath.'/state.json';
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return (is_array($state) ? $state : []) + [
            'status' => 'idle',
            'failed_step' => null,
            'error' => null,
            'target' => null,
            'from' => null,
            'meta' => null,
            'backup' => null,
            'previous' => null,
            'swapped' => [],
            'public_swapped' => [],
            'public_added' => [],
            'maintenance' => false,
            'log' => [],
            'updated_at' => null,
        ];
    }

    /** What the update page needs: state plus which actions are possible now. */
    public function summary(): array
    {
        $state = $this->state();

        return [
            'current' => $this->currentVersion(),
            'status' => $state['status'],
            'failed_step' => $state['failed_step'],
            'error' => $state['error'],
            'target' => $state['target'],
            'from' => $state['from'],
            'maintenance' => $state['maintenance'],
            'log' => array_slice($state['log'], -60),
            'next' => $this->nextStep($state),
            'can_rollback' => $this->allowed('rollback', $state),
            'updated_at' => $state['updated_at'],
        ];
    }

    /**
     * Runs one step. Errors are recorded in the state (and returned) instead of thrown,
     * so the page can show them and offer the right next action.
     *
     * @return array<string, mixed> summary()
     */
    public function run(string $step): array
    {
        if (! in_array($step, self::STEPS, true)) {
            throw new UpdateException('مرحله‌ی ناشناخته.');
        }

        $this->files->ensureDirectoryExists($this->workPath);
        @set_time_limit(600);
        ignore_user_abort(true);

        $lock = fopen($this->workPath.'/update.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new UpdateException('مرحله‌ی دیگری از به‌روزرسانی در حال اجراست؛ کمی صبر کنید.');
        }

        try {
            $state = $this->state();
            if (! $this->allowed($step, $state)) {
                throw new UpdateException('این مرحله الان قابل اجرا نیست؛ صفحه را تازه کنید.');
            }

            $state['error'] = null;
            $state['failed_step'] = null;
            try {
                $this->{$step}($state); // steps update $state in place, so a failure keeps what was done
            } catch (Throwable $e) {
                $state['status'] = 'failed';
                $state['failed_step'] = $step;
                $state['error'] = $e instanceof UpdateException ? $e->getMessage() : 'خطای غیرمنتظره: '.$e->getMessage();
                $this->log($state, $state['error'], 'error');
                Log::error('[updater] '.$step.' failed', ['exception' => $e]);
            }
            $this->save($state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $this->summary();
    }

    // ------------------------------------------------------------------ steps

    protected function download(array &$state): void
    {
        $release = $this->releases->latest();
        if (! $release) {
            throw new UpdateException('هنوز نسخه‌ای روی GitHub منتشر نشده است.');
        }
        $current = $this->currentVersion();
        if (! $release->isNewerThan($current)) {
            throw new UpdateException("نسخه‌ی نصب‌شده ({$current}) به‌روز است.");
        }

        $this->preflight();
        $this->log($state, "شروع به‌روزرسانی از {$current} به {$release->version}");

        $staging = $this->workPath.'/staging';
        $package = $this->workPath.'/package.zip';
        $this->files->deleteDirectory($staging);
        $this->files->deleteDirectory($this->workPath.'/previous');
        @unlink($package);

        $timeout = (int) config('tuka.update.timeout', 180);
        file_put_contents($package, $this->releases->download($release->packageUrl, $timeout));
        $this->log($state, 'بسته دانلود شد ('.number_format(filesize($package) / 1048576, 1).' مگابایت).');

        $expected = strtolower((string) strtok(trim($this->releases->download($release->checksumUrl, 30)), " \t*"));
        if (! preg_match('/^[a-f0-9]{64}$/', $expected) || ! hash_equals($expected, hash_file('sha256', $package))) {
            @unlink($package);
            throw new UpdateException('بسته‌ی دانلودشده با checksum نسخه نمی‌خواند (دانلود ناقص یا خراب). دوباره امتحان کنید.');
        }
        $this->log($state, 'checksum بسته تایید شد.');

        $this->extract($package, $staging);
        $meta = $this->readMeta($staging, $release->version);
        $this->log($state, 'بسته باز شد و ساختار و نیازمندی‌های آن بررسی شد.');

        $state = array_merge($state, [
            'status' => 'downloaded',
            'target' => $release->toArray(),
            'from' => $current,
            'meta' => $meta,
            'backup' => null,
            'previous' => null,
            'swapped' => [],
            'public_swapped' => [],
            'public_added' => [],
        ]);
    }

    protected function backup(array &$state): void
    {
        $dir = dirname($this->workPath).'/backups';
        $this->files->ensureDirectoryExists($dir);
        $stamp = now()->format('Ymd-His');
        $file = "{$dir}/db-{$state['from']}-{$stamp}".(config('database.default') === 'sqlite' ? '.sqlite' : '.sql.gz');

        $info = $this->backups->dump($file);
        if (! $this->backups->isComplete($file)) {
            throw new UpdateException('بکاپ دیتابیس کامل نشد؛ به‌روزرسانی ادامه پیدا نکرد.');
        }
        $this->pruneBackups($dir, keep: 5);
        $this->log($state, 'بکاپ دیتابیس گرفته شد: '.basename($file).' ('.number_format($info['bytes'] / 1024).' کیلوبایت).');

        $state['status'] = 'backed_up';
        $state['backup'] = $file;
    }

    protected function install(array &$state): void
    {
        $staging = $this->workPath.'/staging';
        $previous = $this->workPath.'/previous';
        $meta = $state['meta'];
        $this->readMeta($staging, $state['target']['version']); // staging must still be intact

        $this->enterMaintenance();
        $state['maintenance'] = true;
        $this->log($state, 'سامانه به حالت تعمیر رفت.');
        $this->save($state);

        $this->files->deleteDirectory($previous);
        $this->files->ensureDirectoryExists($previous.'/public');
        $state['previous'] = $previous;
        $state['swapped'] = [];
        $state['public_swapped'] = [];
        $state['public_added'] = [];

        try {
            foreach (array_merge($meta['replace'], $meta['files']) as $name) {
                if (! file_exists("{$staging}/{$name}")) {
                    continue;
                }
                if (file_exists("{$this->basePath}/{$name}")) {
                    $this->move("{$this->basePath}/{$name}", "{$previous}/{$name}");
                }
                $state['swapped'][] = $name;
                $this->move("{$staging}/{$name}", "{$this->basePath}/{$name}");
            }
            $this->installPublic("{$staging}/public", "{$previous}/public", $state);
        } catch (Throwable $e) {
            $this->restoreFiles($state);
            $this->leaveMaintenance();
            $state['maintenance'] = false;
            $state['previous'] = null;
            throw new UpdateException('جایگزینی فایل‌ها ناموفق بود و فایل‌های قبلی برگردانده شدند: '.$e->getMessage());
        }

        $this->resetOpcache();
        $this->log($state, 'فایل‌های نسخه‌ی '.$state['target']['version'].' جایگزین شدند.');

        $state['status'] = 'installed';
    }

    protected function finalize(array &$state): void
    {
        $output = $this->artisan('migrate', ['--force' => true]);
        $this->log($state, 'migration اجرا شد.'.($output !== '' ? ' '.$output : ''));

        $this->artisan('optimize:clear');
        $this->artisan('optimize');
        $this->leaveMaintenance();
        $state['maintenance'] = false;
        $this->log($state, 'کش‌ها بازسازی شدند و سامانه از حالت تعمیر خارج شد.');

        $this->files->deleteDirectory($this->workPath.'/staging');
        @unlink($this->workPath.'/package.zip');
        $this->log($state, 'به‌روزرسانی به نسخه‌ی '.$this->currentVersion().' کامل شد.');

        $state['status'] = 'done';
        $state['maintenance'] = false;
    }

    protected function rollback(array &$state): void
    {
        if (! $state['backup'] || ! $this->backups->isComplete($state['backup'])) {
            throw new UpdateException('بکاپ دیتابیس این به‌روزرسانی در دسترس نیست؛ بازگردانی خودکار ممکن نیست.');
        }

        $this->enterMaintenance();
        $state['maintenance'] = true;
        $this->save($state);

        // Database first, while the code that wrote the dump format is still loaded.
        $this->backups->restore($state['backup']);
        $this->log($state, 'دیتابیس از بکاپ '.basename($state['backup']).' بازگردانی شد.');

        $this->restoreFiles($state);
        $this->resetOpcache();
        $this->leaveMaintenance();
        $this->log($state, 'فایل‌های نسخه‌ی '.($state['from'] ?? 'قبلی').' برگردانده شدند و سامانه از حالت تعمیر خارج شد.');

        $state = array_merge($state, [
            'status' => 'rolled_back',
            'maintenance' => false,
            'previous' => null,
            'swapped' => [],
            'public_swapped' => [],
            'public_added' => [],
        ]);
    }

    // ------------------------------------------------------------------ rules

    protected function allowed(string $step, array $state): bool
    {
        $status = $state['status'];
        $failed = $status === 'failed' ? $state['failed_step'] : null;
        $hasPrevious = $state['previous'] && is_dir($state['previous']);

        return match ($step) {
            'download' => in_array($status, ['idle', 'done', 'rolled_back'], true) || in_array($failed, ['download', 'backup'], true)
                || ($failed === 'install' && ! $state['maintenance']),
            'backup' => $status === 'downloaded',
            'install' => $status === 'backed_up',
            'finalize' => $status === 'installed' || $failed === 'finalize',
            'rollback' => $hasPrevious && ($status === 'installed' || $status === 'done' || in_array($failed, ['finalize', 'rollback'], true)),
            default => false,
        };
    }

    protected function nextStep(array $state): ?string
    {
        return match (true) {
            $state['status'] === 'downloaded' => 'backup',
            $state['status'] === 'backed_up' => 'install',
            $state['status'] === 'installed' => 'finalize',
            default => null,
        };
    }

    // ------------------------------------------------------------------ helpers

    protected function preflight(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new UpdateException('افزونه‌ی zip در PHP هاست فعال نیست؛ آن را از بخش PHP Extensions دایرکت‌ادمین فعال کنید.');
        }
        foreach (array_unique([$this->basePath, $this->publicPath, dirname($this->workPath)]) as $dir) {
            if (! is_writable($dir)) {
                throw new UpdateException("PHP اجازه‌ی نوشتن در «{$dir}» را ندارد؛ مالک و دسترسی پوشه را بررسی کنید.");
            }
        }
        $free = @disk_free_space($this->workPath);
        if ($free !== false && $free < 250 * 1024 * 1024) {
            throw new UpdateException('فضای خالی هاست کمتر از ۲۵۰ مگابایت است.');
        }
    }

    protected function extract(string $package, string $staging): void
    {
        $zip = new ZipArchive;
        if ($zip->open($package) !== true) {
            throw new UpdateException('فایل بسته قابل باز کردن نیست.');
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            $name = (string) $entry['name'];
            // Reject absolute paths and "../" (zip slip).
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('/^[A-Za-z]:/', $name)) {
                $zip->close();
                throw new UpdateException('بسته شامل مسیر غیرمجاز است و نصب نشد.');
            }
            $total += (int) $entry['size'];
        }
        if ($total > self::MAX_UNPACKED_BYTES) {
            $zip->close();
            throw new UpdateException('حجم بسته بیش از حد مجاز است.');
        }

        $this->files->ensureDirectoryExists($staging);
        if (! $zip->extractTo($staging)) {
            $zip->close();
            throw new UpdateException('باز کردن بسته ناموفق بود؛ فضای هاست و دسترسی‌ها را بررسی کنید.');
        }
        $zip->close();
    }

    /** @return array{version: string, php: string, replace: list<string>, files: list<string>, extensions: list<string>} */
    protected function readMeta(string $staging, string $version): array
    {
        foreach (self::REQUIRED as $required) {
            if (! file_exists("{$staging}/{$required}")) {
                throw new UpdateException("بسته ناقص است ({$required} وجود ندارد).");
            }
        }

        $meta = json_decode((string) file_get_contents("{$staging}/release.json"), true);
        if (! is_array($meta) || ($meta['version'] ?? null) !== $version || trim((string) file_get_contents("{$staging}/VERSION")) !== $version) {
            throw new UpdateException('نسخه‌ی داخل بسته با نسخه‌ی منتشرشده یکی نیست.');
        }

        $replace = array_values(array_intersect((array) ($meta['replace'] ?? []), self::ALLOWED));
        $files = array_values(array_intersect((array) ($meta['files'] ?? []), self::ALLOWED));
        if (! in_array('vendor', $replace, true) || ! in_array('app', $replace, true)) {
            throw new UpdateException('فهرست فایل‌های بسته معتبر نیست.');
        }

        $php = (string) ($meta['php'] ?? '');
        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $php, $m) && version_compare(PHP_VERSION, $m[1], '<')) {
            throw new UpdateException("این نسخه به PHP {$m[1]} یا بالاتر نیاز دارد؛ نسخه‌ی PHP هاست ".PHP_VERSION.' است. نسخه PHP را از دایرکت‌ادمین تغییر دهید.');
        }
        $missing = array_values(array_filter((array) ($meta['extensions'] ?? []), fn ($ext) => ! extension_loaded($ext)));
        if ($missing !== []) {
            throw new UpdateException('این افزونه‌های PHP روی هاست فعال نیستند: '.implode('، ', $missing));
        }

        return ['version' => $version, 'php' => $php, 'replace' => $replace, 'files' => $files, 'extensions' => (array) ($meta['extensions'] ?? [])];
    }

    /** Copies the package's public files into the web root, keeping the host's own .htaccess. */
    protected function installPublic(string $from, string $backup, array &$state): void
    {
        if (! is_dir($from)) {
            return;
        }

        $entries = [];
        foreach (array_diff(scandir($from), ['.', '..']) as $entry) {
            if ($entry === 'vendor' && is_dir("{$from}/vendor")) {
                foreach (array_diff(scandir("{$from}/vendor"), ['.', '..']) as $sub) {
                    $entries[] = "vendor/{$sub}"; // only replace the packages we ship (vendor/livewire)
                }
            } elseif (! ($entry === '.htaccess' && file_exists("{$this->publicPath}/.htaccess"))) {
                $entries[] = $entry;
            }
        }

        foreach ($entries as $relative) {
            $target = "{$this->publicPath}/{$relative}";
            if (file_exists($target)) {
                $this->move($target, "{$backup}/{$relative}");
                $state['public_swapped'][] = $relative;
            } else {
                $state['public_added'][] = $relative;
            }
            $this->move("{$from}/{$relative}", $target);
        }
    }

    /** Puts the previous code and public files back (used by rollback and by a failed install). */
    protected function restoreFiles(array $state): void
    {
        $previous = (string) $state['previous'];
        $discard = $this->workPath.'/discarded-'.now()->format('Ymd-His');

        foreach ($state['swapped'] as $name) {
            if (file_exists("{$this->basePath}/{$name}")) {
                $this->move("{$this->basePath}/{$name}", "{$discard}/{$name}");
            }
            if (file_exists("{$previous}/{$name}")) {
                $this->move("{$previous}/{$name}", "{$this->basePath}/{$name}");
            }
        }
        foreach ($state['public_added'] as $relative) {
            if (file_exists("{$this->publicPath}/{$relative}")) {
                $this->move("{$this->publicPath}/{$relative}", "{$discard}/public/{$relative}");
            }
        }
        foreach ($state['public_swapped'] as $relative) {
            if (file_exists("{$this->publicPath}/{$relative}")) {
                $this->move("{$this->publicPath}/{$relative}", "{$discard}/public/{$relative}");
            }
            if (file_exists("{$previous}/public/{$relative}")) {
                $this->move("{$previous}/public/{$relative}", "{$this->publicPath}/{$relative}");
            }
        }

        $this->files->deleteDirectory($discard);
        $this->files->deleteDirectory($previous);
    }

    /** rename(), or copy + delete when source and target are on different filesystems. */
    protected function move(string $from, string $to): void
    {
        $this->files->ensureDirectoryExists(dirname($to));
        if (@rename($from, $to)) {
            return;
        }

        $copied = is_dir($from) ? $this->files->copyDirectory($from, $to) : @copy($from, $to);
        if (! $copied) {
            throw new UpdateException("جابه‌جایی «{$from}» ممکن نشد.");
        }
        is_dir($from) ? $this->files->deleteDirectory($from) : @unlink($from);
    }

    protected function pruneBackups(string $dir, int $keep): void
    {
        $backups = glob($dir.'/db-*') ?: [];
        usort($backups, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($backups, $keep) as $old) {
            @unlink($old);
        }
    }

    protected function enterMaintenance(): void
    {
        Artisan::call('down', ['--retry' => 60, '--refresh' => 20]);
    }

    /** Same as `php artisan up`, without loading command classes from code that may just have been swapped. */
    protected function leaveMaintenance(): void
    {
        @unlink(storage_path('framework/maintenance.php'));
        app()->maintenanceMode()->deactivate();
    }

    protected function artisan(string $command, array $parameters = []): string
    {
        $exitCode = Artisan::call($command, $parameters);
        $output = trim(preg_replace('/\s+/', ' ', Artisan::output()) ?? '');
        if ($exitCode !== 0) {
            throw new UpdateException("فرمان {$command} با خطا تمام شد: {$output}");
        }

        return mb_substr($output, 0, 500);
    }

    protected function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    protected function log(array &$state, string $message, string $level = 'info'): void
    {
        $state['log'][] = ['at' => now()->toIso8601String(), 'level' => $level, 'message' => $message];
        $state['log'] = array_slice($state['log'], -200);
        Log::log($level === 'error' ? 'error' : 'info', '[updater] '.$message);
    }

    protected function save(array $state): void
    {
        $state['updated_at'] = now()->toIso8601String();
        $this->files->ensureDirectoryExists($this->workPath);
        file_put_contents($this->workPath.'/state.json', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }
}
