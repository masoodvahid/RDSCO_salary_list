<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzips HTML and JSON responses when the web server does not. The payroll grid sends large and very
 * repetitive HTML (about 1 MB for a hundred people, some 40 KB gzipped), so on a host without
 * compression the transfer is most of the wait. Nothing changes when the server or PHP already
 * compresses (the response then has a Content-Encoding, or zlib.output_compression is on).
 * Turned off with TUKA_GZIP=false.
 */
final class CompressResponse
{
    private const MIN_BYTES = 2048;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $compressed = gzencode((string) $response->getContent(), 6);
        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->setVary('Accept-Encoding', false);

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        if (! config('tuka.gzip') || ! function_exists('gzencode') || $this->phpCompresses()) {
            return false;
        }
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse || $request->isMethod('HEAD')) {
            return false;
        }
        if ($response->headers->has('Content-Encoding') || ! str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip')) {
            return false;
        }

        $type = strtolower((string) $response->headers->get('Content-Type'));
        if (! str_contains($type, 'text/html') && ! str_contains($type, 'application/json')) {
            return false;
        }

        $content = $response->getContent();

        return is_string($content) && strlen($content) >= self::MIN_BYTES;
    }

    /** zlib.output_compression may be "1", "On" or a buffer size such as "4096". */
    private function phpCompresses(): bool
    {
        $setting = strtolower(trim((string) ini_get('zlib.output_compression')));

        return ! in_array($setting, ['', '0', 'off', 'false', 'no'], true);
    }
}
