<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\Update\ReleaseChecker;
use App\Services\Update\UpdateException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDatabaseBackup;
use Tests\Support\FakeUpdateManager;
use Tests\TestCase;
use ZipArchive;

class UpdaterTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $core;

    private string $web;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tuka.update.repository' => 'acme/tukahr', 'tuka.update.token' => null]);

        $this->root = sys_get_temp_dir().'/tuka-update-'.uniqid();
        $this->core = $this->root.'/core';
        $this->web = $this->root.'/public_html';

        // The installed (old) application.
        $this->put($this->core, [
            'app/Marker.php' => 'old',
            'bootstrap/app.php' => 'old',
            'config/app.php' => 'old',
            'database/x.php' => 'old',
            'resources/views/x.blade.php' => 'old',
            'routes/web.php' => 'old',
            'vendor/autoload.php' => 'old',
            'artisan' => 'old',
            'VERSION' => "1.1.0\n",
            '.env' => 'APP_KEY=secret',
            'storage/app/keep.txt' => 'data',
        ]);
        $this->put($this->web, [
            'index.php' => 'old index',
            '.htaccess' => 'host rules',
            'build/old.css' => 'old css',
            'js/sheet-grid.js' => 'old js',
            'vendor/livewire/livewire.min.js' => 'old livewire',
            'vendor/other/keep.js' => 'other package',
            'unrelated.txt' => 'keep me',
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    /** @param array<string, string> $files */
    private function put(string $base, array $files): void
    {
        foreach ($files as $path => $content) {
            @mkdir(dirname("{$base}/{$path}"), 0777, true);
            file_put_contents("{$base}/{$path}", $content);
        }
    }

    private function package(string $version = '1.2.0', array $extra = []): string
    {
        $files = [
            'app/Marker.php' => 'new',
            'bootstrap/app.php' => 'new',
            'config/app.php' => 'new',
            'database/x.php' => 'new',
            'resources/views/x.blade.php' => 'new',
            'routes/web.php' => 'new',
            'vendor/autoload.php' => 'new',
            'artisan' => 'new',
            'composer.json' => '{}',
            'VERSION' => $version."\n",
            'release.json' => json_encode([
                'version' => $version,
                'php' => '^8.4',
                'extensions' => [],
                'replace' => ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'vendor', '../../etc'],
                'files' => ['artisan', 'composer.json', 'VERSION', 'release.json'],
            ]),
            'public/index.php' => 'new index',
            'public/.htaccess' => 'package rules',
            'public/build/manifest.json' => '{}',
            'public/js/sheet-grid.js' => 'new js',
            'public/vendor/livewire/livewire.min.js' => 'new livewire',
            'storage/app/keep.txt' => 'must not overwrite',
            '.env' => 'APP_KEY=from-package',
        ] + $extra;

        $path = $this->root.'/package-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function fakeGitHub(string $zip, ?string $checksum = null, string $version = '1.2.0'): void
    {
        Http::fake([
            'api.github.com/repos/acme/tukahr/releases/latest' => Http::response([
                'tag_name' => "v{$version}",
                'name' => "نسخه {$version}",
                'body' => "## تغییرات\n- مورد اول",
                'published_at' => '2026-10-01T08:00:00Z',
                'html_url' => "https://github.com/acme/tukahr/releases/tag/v{$version}",
                'assets' => [
                    ['name' => "tukahr-{$version}.zip", 'size' => filesize($zip), 'url' => 'https://api.github.com/assets/1', 'browser_download_url' => "https://dl.example.test/tukahr-{$version}.zip"],
                    ['name' => "tukahr-{$version}.zip.sha256", 'size' => 90, 'url' => 'https://api.github.com/assets/2', 'browser_download_url' => "https://dl.example.test/tukahr-{$version}.zip.sha256"],
                ],
            ]),
            "dl.example.test/tukahr-{$version}.zip" => Http::response(file_get_contents($zip)),
            "dl.example.test/tukahr-{$version}.zip.sha256" => Http::response(($checksum ?? hash_file('sha256', $zip))."  tukahr-{$version}.zip\n"),
        ]);
    }

    private function manager(?FakeDatabaseBackup $backup = null): FakeUpdateManager
    {
        return new FakeUpdateManager(
            app(ReleaseChecker::class),
            $backup ?? new FakeDatabaseBackup,
            $this->core,
            $this->web,
            $this->core.'/storage/app/updates',
        );
    }

    public function test_release_checker_reads_the_latest_release(): void
    {
        $this->fakeGitHub($this->package());

        $release = app(ReleaseChecker::class)->latest();

        $this->assertSame('1.2.0', $release->version);
        $this->assertSame('https://dl.example.test/tukahr-1.2.0.zip', $release->packageUrl);
        $this->assertTrue($release->isNewerThan('1.1.9'));
        $this->assertTrue($release->isNewerThan('dev'));
        $this->assertFalse($release->isNewerThan('1.2.0'));
    }

    public function test_private_repository_uses_the_token_and_api_asset_urls(): void
    {
        config(['tuka.update.token' => 'ghp_test']);
        $this->fakeGitHub($this->package());

        $release = app(ReleaseChecker::class)->latest();

        $this->assertSame('https://api.github.com/assets/1', $release->packageUrl);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_test'));
    }

    public function test_release_without_package_is_not_offered(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.3.0', 'assets' => []])]);

        $this->expectException(UpdateException::class);
        app(ReleaseChecker::class)->latest();
    }

    public function test_no_release_yet_returns_null(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->assertNull(app(ReleaseChecker::class)->latest());
    }

    public function test_full_update_then_rollback(): void
    {
        $this->fakeGitHub($this->package());
        $backup = new FakeDatabaseBackup;
        $updates = $this->manager($backup);

        $this->assertSame('downloaded', $updates->run('download')['status']);
        $this->assertSame('backed_up', $updates->run('backup')['status']);
        $summary = $updates->run('install');
        $this->assertSame('installed', $summary['status'], (string) $summary['error']);

        // Code swapped, old code kept for rollback, private data untouched.
        $this->assertSame('new', file_get_contents($this->core.'/app/Marker.php'));
        $this->assertSame('new', file_get_contents($this->core.'/vendor/autoload.php'));
        $this->assertSame('old', file_get_contents($this->core.'/storage/app/updates/previous/app/Marker.php'));
        $this->assertSame('APP_KEY=secret', file_get_contents($this->core.'/.env'));
        $this->assertSame('data', file_get_contents($this->core.'/storage/app/keep.txt'));
        $this->assertSame('1.2.0', $updates->currentVersion());
        $this->assertFileDoesNotExist($this->root.'/etc');

        // Public files: replaced, the host's .htaccess and other files kept.
        $this->assertSame('new index', file_get_contents($this->web.'/index.php'));
        $this->assertSame('host rules', file_get_contents($this->web.'/.htaccess'));
        $this->assertFileExists($this->web.'/build/manifest.json');
        $this->assertFileDoesNotExist($this->web.'/build/old.css');
        $this->assertSame('new livewire', file_get_contents($this->web.'/vendor/livewire/livewire.min.js'));
        $this->assertSame('other package', file_get_contents($this->web.'/vendor/other/keep.js'));
        $this->assertSame('keep me', file_get_contents($this->web.'/unrelated.txt'));

        $summary = $updates->run('finalize');
        $this->assertSame('done', $summary['status']);
        $this->assertSame(['down', 'migrate', 'optimize:clear', 'optimize', 'up'], $updates->calls);
        $this->assertDirectoryDoesNotExist($this->core.'/storage/app/updates/staging');
        $this->assertTrue($summary['can_rollback']);

        $summary = $updates->run('rollback');
        $this->assertSame('rolled_back', $summary['status'], (string) $summary['error']);
        $this->assertNotNull($backup->restored);
        $this->assertSame('old', file_get_contents($this->core.'/app/Marker.php'));
        $this->assertSame('1.1.0', $updates->currentVersion());
        $this->assertSame('old index', file_get_contents($this->web.'/index.php'));
        $this->assertSame('old css', file_get_contents($this->web.'/build/old.css'));
        $this->assertFileDoesNotExist($this->web.'/build/manifest.json');
        $this->assertSame('old livewire', file_get_contents($this->web.'/vendor/livewire/livewire.min.js'));
        $this->assertSame('old js', file_get_contents($this->web.'/js/sheet-grid.js'));
        $this->assertSame('APP_KEY=secret', file_get_contents($this->core.'/.env'));
    }

    public function test_checksum_mismatch_stops_before_anything_changes(): void
    {
        $this->fakeGitHub($this->package(), str_repeat('a', 64));
        $updates = $this->manager();

        $summary = $updates->run('download');

        $this->assertSame('failed', $summary['status']);
        $this->assertStringContainsString('checksum', $summary['error']);
        $this->assertSame('old', file_get_contents($this->core.'/app/Marker.php'));
        $this->assertFileDoesNotExist($this->core.'/storage/app/updates/package.zip');
    }

    public function test_zip_slip_is_rejected(): void
    {
        $this->fakeGitHub($this->package(extra: ['../escape.php' => 'evil']));

        $summary = $this->manager()->run('download');

        $this->assertSame('failed', $summary['status']);
        $this->assertStringContainsString('مسیر غیرمجاز', $summary['error']);
        $this->assertFileDoesNotExist($this->core.'/storage/app/escape.php');
    }

    public function test_up_to_date_and_out_of_order_steps(): void
    {
        $this->fakeGitHub($this->package('1.1.0'), version: '1.1.0');
        $updates = $this->manager();

        $this->assertStringContainsString('به‌روز است', $updates->run('download')['error']);

        $this->expectException(UpdateException::class);
        $updates->run('install');
    }

    public function test_failed_install_puts_the_old_files_back(): void
    {
        $this->fakeGitHub($this->package());
        $updates = new class(app(ReleaseChecker::class), new FakeDatabaseBackup, $this->core, $this->web, $this->core.'/storage/app/updates') extends FakeUpdateManager
        {
            protected function installPublic(string $from, string $backup, array &$state): void
            {
                throw new \RuntimeException('disk full');
            }
        };

        $updates->run('download');
        $updates->run('backup');
        $summary = $updates->run('install');

        $this->assertSame('failed', $summary['status']);
        $this->assertSame('install', $summary['failed_step']);
        $this->assertFalse($summary['maintenance']);
        $this->assertSame('old', file_get_contents($this->core.'/app/Marker.php'));
        $this->assertSame('1.1.0', $updates->currentVersion());
        $this->assertSame(['down', 'up'], $updates->calls);
    }

    public function test_update_endpoints_are_for_managers_and_work_in_maintenance_mode(): void
    {
        $manager = User::factory()->manager()->create();
        $editor = User::factory()->editor(Project::factory()->create())->create();
        $this->fakeGitHub($this->package());

        $this->actingAs($editor)->getJson(route('system.update.status'))->assertForbidden();
        $this->actingAs($editor)->get(route('system.update'))->assertForbidden();

        $this->actingAs($manager)->get(route('system.update'))->assertOk()->assertSee('به‌روزرسانی سامانه');
        $this->actingAs($manager)->postJson(route('system.update.check'))
            ->assertOk()
            ->assertJsonPath('release.version', '1.2.0')
            ->assertJsonPath('available', true);
        $this->actingAs($manager)->postJson(route('system.update.run', 'install'))->assertStatus(409);

        $this->app->maintenanceMode()->activate(['except' => [], 'redirect' => null, 'retry' => 60, 'refresh' => null, 'secret' => null, 'status' => 503, 'template' => null]);
        try {
            $this->actingAs($manager)->getJson(route('system.update.status'))->assertOk();
            $this->actingAs($manager)->get(route('dashboard'))->assertStatus(503);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }
}
