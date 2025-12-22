<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

use Flytachi\Winter\Kernel\Process\Core\Dispatch;
use Flytachi\Winter\Kernel\Process\Traits\ThreatJobHandler;

abstract class ThreadJob extends Dispatch
{
    use ThreatJobHandler;

    final protected function resolutionStart(): void
    {
        parent::resolutionStart();
        if (PHP_SAPI === 'cli') {
            pcntl_async_signals(true);
            pcntl_signal(SIGHUP, function () {
                $this->signClose();
            });
            pcntl_signal(SIGINT, function () {
                $this->signInterrupt();
            });
            pcntl_signal(SIGTERM, function () {
                $this->signTermination();
            });
        }
    }

    final protected function resolutionEnd(): void
    {
    }
}
