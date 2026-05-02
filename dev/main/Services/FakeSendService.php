<?php

namespace Main\Services;

use Flytachi\Winter\K2\Stereotype\Service;
use Flytachi\Winter\Logger\Log;

class FakeSendService extends Service implements SendInterface
{
    public function list(): array
    {
        return [
            'message' => 'Fake - Hello, World!',
        ];
    }

    public function send(): void
    {
        Log::info('Sending Fake message');
    }
}
