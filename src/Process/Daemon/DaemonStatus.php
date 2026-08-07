<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Daemon;

use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Process\ProcessState;
use Flytachi\Winter\Kernel\Process\ProcessStatus;
use Flytachi\Winter\Kernel\Process\ResourceUsage;

/**
 * Status record of a supervised {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon}.
 *
 * A daemon is a {@see ProcessStatus} that runs several worker processes under a
 * supervisor, so its record adds what only a supervisor has: the per-worker
 * fleet snapshot ({@see WorkerStatus}) and how many times a worker has been
 * restarted. A bare process records a plain {@see ProcessStatus}.
 */
final class DaemonStatus extends ProcessStatus
{
    /**
     * @param array<WorkerStatus> $workers Live fleet snapshot, one entry per non-empty slot.
     */
    public function __construct(
        int $pid,
        string $className,
        ProcessState $state,
        Activity $activity,
        int $startedAt,
        int $concurrency,
        public int $restarts,
        public array $workers,
        ?ResourceUsage $usage = null,
    ) {
        parent::__construct($pid, $className, $state, $activity, $startedAt, $concurrency, $usage);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'restarts' => $this->restarts,
            'workers'  => $this->workers,
        ]);
    }
}
