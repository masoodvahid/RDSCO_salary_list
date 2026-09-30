<?php

namespace App\Http\Controllers;

use App\Services\InviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class InviteController extends Controller
{
    public function __invoke(string $token, InviteService $invites): RedirectResponse
    {
        $invitation = $invites->findValid($token);
        if (! $invitation) {
            return redirect()->route('login')->with('status', 'لینک دعوت معتبر نیست یا منقضی شده است.');
        }

        // Someone else is signed in on this browser: sign them out first.
        if (Auth::check() && Auth::id() !== $invitation->user_id) {
            Auth::logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        session(['invite_id' => $invitation->id, 'invite_user_id' => $invitation->user_id]);

        return redirect()->route('login');
    }
}
