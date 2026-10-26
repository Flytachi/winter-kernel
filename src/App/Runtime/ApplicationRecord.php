<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Runtime;

/**
 * The status record of a running application — written by `call run` when it starts,
 * removed when it stops, read by whoever needs to know whether the application is up
 * and what it runs.
 *
 * Like a process record it outlives a master killed without cleanup; whether the master
 * is alive is decided by {@see ApplicationRuntime::isAlive()}, not by the record.
 */
final readonly class ApplicationRecord implements \JsonSerializable
{
    /**
     * @param int $pid The master process.
     * @param class-string<\Flytachi\Winter\Kernel\WinterApplication> $appClass
     * @param int $startedAt When the master started (Unix time).
     * @param string $host Where it runs — {@see ApplicationRuntime::host()}.
     * @param bool $locked Whether the master holds an application lock that works on
     *                     this filesystem; when false, liveness falls back to the PID.
     * @param list<array{kind: string, class: ?string, port: ?int}> $components What it runs.
     */
    public function __construct(
        public int $pid,
        public string $appClass,
        public int $startedAt,
        public string $host,
        public bool $locked,
        public array $components,
    ) {
    }

    /** The reference a component of this run carries in its own record. */
    public function managedBy(): ManagedBy
    {
        return new ManagedBy($this->appClass, $this->pid, $this->startedAt, $this->host);
    }

    /** Start time as a human-readable timestamp with timezone. */
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
            'pid'        => $this->pid,
            'app'        => $this->appClass,
            'started_at' => $this->startedAt,
            'uptime'     => time() - $this->startedAt,
            'host'       => $this->host,
            'components' => $this->components,
        ];
    }
}
