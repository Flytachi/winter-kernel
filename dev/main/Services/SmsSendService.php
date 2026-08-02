<?php

namespace Main\Services;

use Flytachi\Winter\Logger\Log;

class SmsSendService implements SendInterface
{
    public function list(): array
    {
        return [
            'message' => 'SMS - Hello, World!',
        ];
    }

    public function send(): void
    {
        Log::info('Sending SMS message');
    }
}
