<?php

namespace App\Providers;

use App\Services\Otp\KavenegarOtpSender;
use App\Services\Otp\LogOtpSender;
use App\Services\Otp\OtpSender;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OtpSender::class, function ($app) {
            $key = (string) config('services.kavenegar.key');

            if ($key !== '') {
                return new KavenegarOtpSender($key, (int) config('services.kavenegar.timeout', 8));
            }

            // Never silently skip SMS in production: a missing key must fail loudly.
            if (! $app->environment('production')) {
                return new LogOtpSender;
            }

            throw new RuntimeException('KAVENEGAR_API_KEY is not configured.');
        });
    }

    public function boot(): void
    {
        //
    }
}
