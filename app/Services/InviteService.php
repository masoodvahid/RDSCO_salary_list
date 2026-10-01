<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Invite links identify a member so they skip typing their number. They are NOT a login
 * on their own: the member still confirms an SMS code sent to their own mobile.
 */
final class InviteService
{
    public function createLink(User $invitee, ?User $inviter): string
    {
        $token = Str::random(48);

        Invitation::create([
            'user_id' => $invitee->id,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter?->id,
            'expires_at' => now()->addDays((int) config('tuka.invite_ttl_days', 30)),
        ]);

        return route('invite', ['token' => $token]);
    }

    public function findValid(string $token): ?Invitation
    {
        $invitation = Invitation::where('token_hash', hash('sha256', $token))->with('user')->first();

        if (! $invitation || now()->greaterThan($invitation->expires_at) || ! $invitation->user?->is_active) {
            return null;
        }

        return $invitation;
    }

    /** Sends the link by SMS when a Kavenegar sender line is configured. Returns false when not sent. */
    public function sendLinkSms(User $invitee, string $url): bool
    {
        $key = config('services.kavenegar.key');
        $sender = config('services.kavenegar.sender');
        if (blank($key) || blank($sender)) {
            return false;
        }

        $message = "{$invitee->name} عزیز، برای ورود به سامانه لیست حقوق توکا از این لینک استفاده کنید:\n{$url}";

        try {
            $response = Http::timeout((int) config('services.kavenegar.timeout', 8))
                ->asForm()
                ->post("https://api.kavenegar.com/v1/{$key}/sms/send.json", [
                    'receptor' => $invitee->mobile,
                    'sender' => $sender,
                    'message' => $message,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Invite SMS failed', ['error' => $e->getMessage()]);

            return false;
        }

        return $response->successful() && (int) data_get($response->json(), 'return.status') === 200;
    }
}
