<?php

namespace Main;

use Flytachi\Winter\K2\Stereotype\Job;

class S1Job extends Job
{
    public function resolution(mixed $data = null): void
    {
        $this->logger->info('RUN');
        for ($i = 1; $i <= 10; $i++) {
            sleep(1);
            $this->logger->info('Iteration ' . $i);
        }
        $this->logger->info('END');
    }
}
