<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/**
 * Supervises an external worker class ({@see SendProc}) rather than an inline
 * body — exercises the $workerClass path, worker#{n} titles and the per-worker
 * BUSY/IDLE fleet view.
 */
class FleetDaemon extends Daemon
{
    protected int $replicas = 2;
    protected ?string $workerClass = SendProc::class;

    protected function onWorkerStart(int $slot, int $pid): void
    {
        $this->logger->info('FleetDaemon worker#' . ($slot + 1) . " start pid={$pid}");
    }

    protected function onWorkerExit(int $slot, int $pid, bool $crashed): void
    {
        $this->logger->info(
            'FleetDaemon worker#' . ($slot + 1) . " exit pid={$pid} crashed=" . ($crashed ? '1' : '0')
        );
    }
}
