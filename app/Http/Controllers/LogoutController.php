<?php

namespace App\Http\Controllers;

use App\Services\UserActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request, UserActivity $activity): RedirectResponse
    {
        if ($user = $request->user()) {
            $activity->record($user, 'logout', $user);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
