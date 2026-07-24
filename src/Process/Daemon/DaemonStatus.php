<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Daemon;

use Flytachi\Winter\K2\Process\Activity;
use Flytachi\Winter\K2\Process\ProcessState;
use Flytachi\Winter\K2\Process\ProcessStatus;
use Flytachi\Winter\K2\Process\ResourceUsage;

/**
 * Status record of a supervised {@see Daemon}.
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
