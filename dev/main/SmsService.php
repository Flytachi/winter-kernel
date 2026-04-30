<?php

namespace Main;

use Flytachi\Winter\K2\Stereotype\Service;

class SmsService extends Service
{
    public function list(): array
    {
        return [
            'message' => 'Hello, World!',
        ];
    }

    public function message(): string
    {
        return 'Sms Message';
    }
}
