<?php

namespace Tests\Support;

use App\Services\Otp\OtpSender;

final class FakeOtpSender implements OtpSender
{
    /** @var list<array{mobile:string, code:string, template:string}> */
    public array $sent = [];

    public function send(string $mobile, string $code, string $template): ?string
    {
        $this->sent[] = compact('mobile', 'code', 'template');

        return 'fake-'.count($this->sent);
    }

    public function lastCodeFor(string $mobile): ?string
    {
        foreach (array_reverse($this->sent) as $message) {
            if ($message['mobile'] === $mobile) {
                return $message['code'];
            }
        }

        return null;
    }
}
