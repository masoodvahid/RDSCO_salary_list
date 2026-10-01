<?php

namespace App\Http\Controllers;

use App\Services\SheetAccess;
use App\Services\Update\UpdateException;
use App\Services\Update\UpdateManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * JSON endpoints of the in-app updater (managers only). They stay reachable during maintenance
 * mode (see bootstrap/app.php), and each step is its own request so that "finalize" runs with
 * the newly installed code. Keep these URLs and response shapes stable across releases.
 */
class UpdateController extends Controller
{
    public function status(Request $request, UpdateManager $updates): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json($updates->summary());
    }

    public function check(Request $request, UpdateManager $updates): JsonResponse
    {
        $this->authorizeManage($request);

        try {
            $release = $updates->check();
        } catch (UpdateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $current = $updates->currentVersion();

        return response()->json([
            'current' => $current,
            'available' => $release?->isNewerThan($current) ?? false,
            'release' => $release ? $release->toArray() + [
                'notes_html' => (string) Str::markdown($release->notes ?: '—', ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            ] : null,
        ]);
    }

    public function run(Request $request, UpdateManager $updates, string $step): JsonResponse
    {
        $this->authorizeManage($request);

        try {
            $summary = $updates->run($step);
        } catch (UpdateException $e) {
            return response()->json(['message' => $e->getMessage()] + $updates->summary(), 409);
        }

        return response()->json($summary, $summary['status'] === 'failed' ? 422 : 200);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(app(SheetAccess::class)->canManage($request->user()), 403);
    }
}
