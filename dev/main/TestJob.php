<?php

namespace Flytachi\Winter\Kernel\Dev\Main;

use Flytachi\Winter\Base\Log\Log;
use Flytachi\Winter\Kernel\Stereotype\Job;

class TestJob extends Job
{
    public function resolution(mixed $data = null): void
    {
        Log::info('tewst');
        $this->logger->info('RUN');
        for ($i = 0; $i < 10; $i++) {
            $this->logger->info('running 10/'. $i);
            sleep(1);
        }
        $this->logger->info('END');
    }
}
