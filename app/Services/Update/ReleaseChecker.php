<?php

namespace App\Services\Update;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Reads the latest published (non-draft, non-prerelease) release from GitHub.
 */
class ReleaseChecker
{
    public function latest(): ?Release
    {
        $repository = (string) config('tuka.update.repository');
        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $repository)) {
            throw new UpdateException('آدرس مخزن به‌روزرسانی (TUKA_UPDATE_REPO) معتبر نیست.');
        }

        try {
            $response = $this->client()->get("https://api.github.com/repos/{$repository}/releases/latest");
        } catch (ConnectionException) {
            throw new UpdateException('اتصال به GitHub برقرار نشد. اتصال اینترنت سرور یا دسترسی هاست به github.com را بررسی کنید.');
        }

        if ($response->status() === 404) {
            return null; // no published release yet
        }
        if ($response->status() === 403 || $response->status() === 429) {
            throw new UpdateException('GitHub موقتاً درخواست را محدود کرده است. چند دقیقه بعد دوباره امتحان کنید یا TUKA_UPDATE_TOKEN را تنظیم کنید.');
        }
        if (! $response->successful()) {
            throw new UpdateException('پاسخ GitHub معتبر نبود (کد '.$response->status().').');
        }

        $data = $response->json();
        $tag = (string) ($data['tag_name'] ?? '');
        $version = ltrim($tag, 'vV');
        if (! preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $version)) {
            throw new UpdateException("برچسب آخرین نسخه ({$tag}) شکل نسخه ندارد؛ برچسب باید مثل v1.2.0 باشد.");
        }

        $assets = collect($data['assets'] ?? [])->keyBy('name');
        $package = $assets->get("tukahr-{$version}.zip");
        $checksum = $assets->get("tukahr-{$version}.zip.sha256");
        if (! $package || ! $checksum) {
            throw new UpdateException("بسته‌ی نصب نسخه {$version} هنوز روی GitHub آماده نیست. چند دقیقه بعد دوباره بررسی کنید.");
        }

        return new Release(
            version: $version,
            tag: $tag,
            name: (string) ($data['name'] ?: $tag),
            notes: (string) ($data['body'] ?? ''),
            publishedAt: isset($data['published_at']) ? CarbonImmutable::parse($data['published_at']) : null,
            url: (string) ($data['html_url'] ?? ''),
            packageUrl: $this->downloadUrl($package),
            checksumUrl: $this->downloadUrl($checksum),
            size: (int) ($package['size'] ?? 0),
        );
    }

    /** Downloads a release asset; returns the body. */
    public function download(string $url, int $timeout): string
    {
        try {
            $response = $this->client($timeout)
                ->withHeaders(['Accept' => 'application/octet-stream'])
                ->get($url);
        } catch (ConnectionException) {
            throw new UpdateException('دانلود بسته از GitHub قطع شد. دوباره امتحان کنید.');
        }

        if (! $response->successful()) {
            throw new UpdateException('دانلود بسته ناموفق بود (کد '.$response->status().').');
        }

        return $response->body();
    }

    /** Private repositories need the API asset URL (with the token); public ones use the plain download link. */
    private function downloadUrl(array $asset): string
    {
        return config('tuka.update.token') ? (string) $asset['url'] : (string) $asset['browser_download_url'];
    }

    private function client(int $timeout = 20): PendingRequest
    {
        $request = Http::timeout($timeout)
            ->connectTimeout(10)
            ->withUserAgent('TukaHR-Updater')
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28']);

        $token = config('tuka.update.token');

        return $token ? $request->withToken($token) : $request;
    }
}
