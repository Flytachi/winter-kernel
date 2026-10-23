<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

/**
 * Persisted status record of a {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process}.
 *
 * Written to the runnable store while the process lives, read back by the CLI
 * and the web layer. {@see ResourceUsage} is live and never persisted — it is
 * attached on read via {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::status()}.
 *
 * Serialises to a stable JSON shape so a controller can return it directly. A
 * supervised {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon} records the
 * richer {@see \Flytachi\Winter\Kernel\Process\Daemon\DaemonStatus} subclass.
 */
class ProcessStatus implements \JsonSerializable
{
    /**
     * Whether the instance that wrote this record holds a singleton lock that works on
     * this filesystem — then the lock, not the PID, says whether it is alive. False on
     * a filesystem where `flock` does not exclude (a host directory mounted into Docker
     * Desktop), and in a record written before the field existed.
     *
     * @internal Read by {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::status()}.
     */
    public bool $locked = false;

    public function __construct(
        public int $pid,
        public string $className,
        public ProcessState $state,
        public Activity $activity,
        public int $startedAt,
        public int $concurrency = 0,
        public ?ResourceUsage $usage = null,
        public int $heartbeatAt = 0,
    ) {
    }

    /**
     * Start time as a human-readable timestamp with timezone (for the CLI/web view).
     */
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
            'heartbeat_at' => $this->heartbeatAt,      // last liveness beat (0 = none)
            'usage'       => $this->usage,             // ResourceUsage|null (also JsonSerializable)
        ];
    }
}
