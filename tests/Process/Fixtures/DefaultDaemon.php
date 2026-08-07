<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Fixtures;

use Flytachi\Winter\Kernel\Process\Stereotype\Daemon;

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
