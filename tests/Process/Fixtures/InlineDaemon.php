<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Process\Fixtures;

use Flytachi\Winter\K2\Process\Daemon\Daemon;
use Flytachi\Winter\K2\Process\Daemon\RestartMode;
use Flytachi\Winter\K2\Process\Daemon\RestartPolicy;
use Flytachi\Winter\K2\Process\Daemon\ScalingPolicy;

/**
 * Inline-body daemon (workerRun defined) with every policy overridden and the
 * master hooks recording their calls — drives config-resolution and hook tests.
 */
final class InlineDaemon extends Daemon
{
    protected int $replicas = 3;
    protected float $grace = 5.0;
    protected float $livenessTimeout = 7.0;

    public int $desired = 4;

    /** @var list<array{int, int}> */
    public array $started = [];
    /** @var list<array{int, int, bool}> */
    public array $exited = [];
    /** @var list<array{int, int}> */
    public array $scaled = [];
    public int $ticks = 0;
    public int $reloads = 0;

    protected function desiredReplicas(): int
    {
        return $this->desired;
    }

    protected function scaling(): ScalingPolicy
    {
        return new ScalingPolicy(scaleInterval: 2.0, scaleDownStabilization: 30.0, scaleStep: 2);
    }

    protected function restart(): RestartPolicy
    {
        return new RestartPolicy(mode: RestartMode::ALWAYS, maxRestarts: 4, backoff: 0.25);
    }

    protected function workerRun(): void
    {
    }

    protected function onWorkerStart(int $slot, int $pid): void
    {
        $this->started[] = [$slot, $pid];
    }

    protected function onWorkerExit(int $slot, int $pid, bool $crashed): void
    {
        $this->exited[] = [$slot, $pid, $crashed];
    }

    protected function onScale(int $from, int $to): void
    {
        $this->scaled[] = [$from, $to];
    }

    protected function tick(): void
    {
        $this->ticks++;
    }

    protected function onReload(): void
    {
        $this->reloads++;
    }
}
