<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;
use Flytachi\Winter\Kernel\Process\Daemon\ScalingPolicy;

/**
 * Integration fixture: desiredReplicas() ramps 1 → 4 → 2 with a fast scaling
 * policy, to observe scale-up, damped scale-down and IDLE-first retirement live.
 */
class AutoscaleLoopDaemon extends Daemon
{
    protected int $replicas = 1;
    private float $bootAt = 0.0;

    protected function scaling(): ScalingPolicy
    {
        return new ScalingPolicy(
            scaleInterval: 0.3,
            scaleUpDelay: 0.0,
            scaleDownStabilization: 1.0,
            cooldown: 0.3,
            scaleStep: 4,
        );
    }

    protected function desiredReplicas(): int
    {
        if ($this->bootAt === 0.0) {
            $this->bootAt = microtime(true);
        }
        $elapsed = microtime(true) - $this->bootAt;

        if ($elapsed < 2.0) {
            return 1;
        }
        if ($elapsed < 5.0) {
            return 4;
        }
        return 2;
    }

    protected function workerRun(): void
    {
        while ($this->isRunning()) {
            $this->sleep(0.2);
        }
    }
}
