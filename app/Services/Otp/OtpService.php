<?php

namespace App\Services\Otp;

use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\Digits;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class OtpService
{
    public function __construct(private readonly OtpSender $sender) {}

    /**
     * @param  array<string, mixed>  $context  bound data (e.g. the approval being signed)
     */
    public function issue(User $user, string $purpose, array $context = [], ?string $ip = null, string $errorKey = 'code'): OtpChallenge
    {
        $wait = $this->secondsUntilResend($user, $purpose);
        if ($wait > 0) {
            throw ValidationException::withMessages([
                $errorKey => 'برای ارسال دوباره '.Digits::toPersian($wait).' ثانیه صبر کنید.',
            ]);
        }

        // Only the newest code of a purpose is usable.
        OtpChallenge::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        $length = (int) config('tuka.otp.length', 6);
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        $challenge = OtpChallenge::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'context' => $context ?: null,
            'expires_at' => now()->addSeconds((int) config('tuka.otp.ttl', 120)),
            'ip' => $ip,
        ]);

        $template = $purpose === OtpChallenge::PURPOSE_LOGIN
            ? (string) config('services.kavenegar.otp_template')
            : (string) config('services.kavenegar.approval_template');

        try {
            $messageId = $this->sender->send($user->mobile, $code, $template);
        } catch (OtpDeliveryException $e) {
            $challenge->delete();
            throw ValidationException::withMessages([$errorKey => $e->getMessage()]);
        }

        if ($messageId !== null) {
            $challenge->update(['provider_message_id' => $messageId]);
        }

        return $challenge;
    }

    public function verify(OtpChallenge $challenge, string $code, string $errorKey = 'code'): void
    {
        $code = preg_replace('/\D/', '', Digits::toEnglish($code)) ?? '';

        if (! $challenge->isUsable()) {
            throw ValidationException::withMessages([$errorKey => 'کد منقضی شده است. کد جدید بگیرید.']);
        }

        $challenge->increment('attempts');

        if ($challenge->attempts > (int) config('tuka.otp.max_attempts', 5)) {
            $challenge->update(['expires_at' => now()]);
            throw ValidationException::withMessages([$errorKey => 'تعداد تلاش‌ها بیش از حد مجاز است. کد جدید بگیرید.']);
        }

        if ($code === '' || ! Hash::check($code, $challenge->code_hash)) {
            throw ValidationException::withMessages([$errorKey => 'کد واردشده درست نیست.']);
        }

        // Single use: a conditional update so two parallel requests cannot both consume it.
        $consumed = OtpChallenge::whereKey($challenge->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        if ($consumed !== 1) {
            throw ValidationException::withMessages([$errorKey => 'این کد قبلاً استفاده شده است.']);
        }

        $challenge->refresh();
    }

    public function secondsUntilResend(User $user, string $purpose): int
    {
        $last = OtpChallenge::where('user_id', $user->id)->where('purpose', $purpose)->latest('id')->first();
        if (! $last) {
            return 0;
        }

        $elapsed = (int) $last->created_at->diffInSeconds(now(), true);

        return max(0, (int) config('tuka.otp.resend', 60) - $elapsed);
    }
}
