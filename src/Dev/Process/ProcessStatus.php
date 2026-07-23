<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process;

/**
 * Persisted status record of a {@see Process}.
 *
 * Written to the runnable store while the process lives, read back by the CLI
 * and the web layer. {@see ResourceUsage} is live and never persisted — it is
 * attached on read via {@see Process::status()}.
 *
 * Serialises to a stable JSON shape so a controller can return it directly. A
 * supervised {@see Daemon} records the richer {@see DaemonStatus} subclass.
 */
class ProcessStatus implements \JsonSerializable
{
    public function __construct(
        public int $pid,
        public string $className,
        public ProcessState $state,
        public Activity $activity,
        public int $startedAt,
        public int $concurrency = 0,
        public ?ResourceUsage $usage = null,
    ) {
    }

    public function getStartedAt(): string
    {
        return date('Y-m-d H:i:s P', $this->startedAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'pid'         => $this->pid,
            'class'       => $this->className,
            'state'       => $this->state->name,       // NEW | RUNNING | STOPPING | …
            'activity'    => $this->activity->value,   // idle | busy
            'started_at'  => $this->startedAt,
            'uptime'      => time() - $this->startedAt,
            'concurrency' => $this->concurrency,
            'usage'       => $this->usage,             // ResourceUsage|null (also JsonSerializable)
        ];
    }
}
