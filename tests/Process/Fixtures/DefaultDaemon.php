<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Daemon\Daemon;

/**
 * Daemon that overrides nothing but the body — used to assert the shipped
 * defaults (replicas, grace, policies, liveness).
 */
final class DefaultDaemon extends Daemon
{
    protected function workerRun(): void
    {
    }
}
