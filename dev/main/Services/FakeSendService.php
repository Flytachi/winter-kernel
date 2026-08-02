<?php

namespace Main\Services;

use Flytachi\Winter\Logger\Log;

class FakeSendService implements SendInterface
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
