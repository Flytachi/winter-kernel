<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Traits;

trait ThreadSignalHandler
{
    private function prepareSignalHandler(): void
    {
        if (
            PHP_SAPI === 'cli'
            && empty($_SERVER['REMOTE_ADDR'])
            && function_exists('pcntl_signal')
        ) {
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
}