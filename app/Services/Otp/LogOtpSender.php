<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Local development only: writes the code to the log instead of sending an SMS.
 * Bound only when KAVENEGAR_API_KEY is empty and the app is not in production.
 */
final class LogOtpSender implements OtpSender
{
    public function send(string $mobile, string $code, string $template): ?string
    {
        Log::info("TukaHR OTP [{$template}] for {$mobile}: {$code}");

        return null;
    }
}
