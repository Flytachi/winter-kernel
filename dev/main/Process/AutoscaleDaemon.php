<?php

declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\K2\Process\Daemon\Daemon;
use Flytachi\Winter\K2\Process\Daemon\ScalingPolicy;

/**
 * Autoscaling demo: desiredReplicas() ramps 1 → 5 → 2 over time so the reconcile
 * loop, damping and IDLE-first scale-down are all exercised. Uses a fast scaling
 * policy purely for observability.
 */
class AutoscaleDaemon extends Daemon
{
    protected int $replicas = 1;
    private float $bootAt = 0.0;

    protected function scaling(): ScalingPolicy
    {
        return new ScalingPolicy(
            scaleInterval: 0.5,
            scaleUpDelay: 0.0,
            scaleDownStabilization: 2.0,
            cooldown: 0.5,
            scaleStep: 2,
        );
    }

    protected function desiredReplicas(): int
    {
        if ($this->bootAt === 0.0) {
            $this->bootAt = microtime(true);
        }
        $elapsed = microtime(true) - $this->bootAt;

        if ($elapsed < 3.0) {
            return 1;
        }
        if ($elapsed < 8.0) {
            return 5;
        }
        return 2;
    }

    protected function onScale(int $from, int $to): void
    {
        $this->logger->info("AutoscaleDaemon scaled {$from} → {$to}");
    }

    protected function workerRun(): void
    {
        while ($this->isRunning()) {
            $this->sleep(0.5);
        }
    }
}
