<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process;

use Flytachi\Winter\K2\Process\Entity\TStats;

/**
 * Persisted status record of a {@see Process}.
 *
 * Written to the runnable store while the process lives, read back by the CLI
 * and (later) the web layer. Resource {@see TStats} are live and never
 * persisted — they are attached on read via {@see Process::status()}.
 */
final class ProcessStatus
{
    /**
     * @param array<int> $workers Worker PIDs supervised by a daemon (empty for a bare process).
     */
    public function __construct(
        public int $pid,
        public string $className,
        public ProcessState $state,
        public int $startedAt,
        public int $concurrency = 0,
        public int $restarts = 0,
        public array $workers = [],
        public ?TStats $stats = null,
    ) {
    }

    public function getStartedAt(): string
    {
        return date('Y-m-d H:i:s P', $this->startedAt);
    }
}
