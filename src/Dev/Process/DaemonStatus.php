<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process;

/**
 * Status record of a supervised {@see Daemon}.
 *
 * A daemon is a {@see Process} that runs several worker processes under a
 * supervisor, so its record adds what only a supervisor has: the live worker
 * PIDs and how many times a worker has been restarted. A bare {@see Process}
 * records a plain {@see ProcessStatus} and never carries these.
 */
final class DaemonStatus extends ProcessStatus
{
    /**
     * @param array<int> $workers Live worker PIDs under the supervisor.
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
