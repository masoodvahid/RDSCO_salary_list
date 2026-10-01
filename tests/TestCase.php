<?php

namespace Tests;

use App\Services\Otp\OtpSender;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeOtpSender;

abstract class TestCase extends BaseTestCase
{
    protected FakeOtpSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['tuka.otp.resend' => 0]);

        $this->sms = new FakeOtpSender;
        $this->app->instance(OtpSender::class, $this->sms);
    }
}
