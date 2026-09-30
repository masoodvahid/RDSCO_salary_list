<?php

namespace App\Services\Otp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class KavenegarOtpSender implements OtpSender
{
    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeout = 8,
    ) {}

    public function send(string $mobile, string $code, string $template): ?string
    {
        try {
            $response = Http::timeout($this->timeout)
                ->asForm()
                ->post("https://api.kavenegar.com/v1/{$this->apiKey}/verify/lookup.json", [
                    'receptor' => $mobile,
                    'token' => $code,
                    'template' => $template,
                ]);
        } catch (ConnectionException $e) {
            Log::error('Kavenegar connection failed', ['error' => $e->getMessage()]);
            throw new OtpDeliveryException('ارتباط با سرویس پیامک برقرار نشد. دوباره تلاش کنید.');
        }

        $status = (int) data_get($response->json(), 'return.status', $response->status());

        if ($response->failed() || $status !== 200) {
            // Never log the code or the API key.
            Log::error('Kavenegar lookup failed', [
                'status' => $status,
                'message' => data_get($response->json(), 'return.message'),
                'template' => $template,
            ]);
            throw new OtpDeliveryException('ارسال پیامک ناموفق بود. دوباره تلاش کنید.');
        }

        $messageId = data_get($response->json(), 'entries.0.messageid');

        return $messageId === null ? null : (string) $messageId;
    }
}
