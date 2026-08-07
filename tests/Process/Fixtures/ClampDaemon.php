<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

/**
 * Daemon configured with out-of-range values, to verify the introspection
 * accessors clamp them (replicas >= 1, desired >= 0, grace/liveness >= 0).
 */
final class ClampDaemon extends Daemon
{
    protected int $replicas = 0;
    protected float $grace = -5.0;
    protected float $livenessTimeout = -1.0;

    protected function desiredReplicas(): int
    {
        return -3;
    }

    protected function workerRun(): void
    {
    }
}
