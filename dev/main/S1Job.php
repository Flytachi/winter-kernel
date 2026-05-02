<?php

namespace Main;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Stereotype\Job;
use Main\Services\SmsSendService;

class S1Job extends Job
{
    #[Autowired]
    private SmsSendService $service;

    public function resolution(mixed $data = null): void
    {
        $this->logger->info('RUN');
        $this->service->send();
        for ($i = 1; $i <= 10; $i++) {
            sleep(1);
            $this->logger->info('Iteration ' . $i);
        }
        $this->logger->info('END');
    }
}
