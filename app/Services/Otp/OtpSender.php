<?php

namespace App\Services\Otp;

interface OtpSender
{
    /**
     * Delivers a one-time code using a Kavenegar verify/lookup template.
     *
     * @return string|null provider message id
     *
     * @throws OtpDeliveryException
     */
    public function send(string $mobile, string $code, string $template): ?string;
}
