<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process;

/**
 * A resource snapshot of a running process, taken from `ps`.
 *
 * Meant for occasional, on-demand observation — the CLI (`status -v`) and the
 * web layer — never a hot path: each read forks the `ps` binary. Use it to look
 * at a process from the outside; a process does not measure itself with it.
 */
final class ResourceUsage implements \JsonSerializable
{
    public function __construct(
        public int $pid,
        public int $ppid,
        public string $user,
        public float $cpu,
        public float $memory,
        public int $rssKb,
        public string $elapsed,
        public string $command,
    ) {
    }

    /**
     * Reads the current usage of a process, or null if it is gone or `ps` failed.
     */
    public static function ofPid(int $pid): ?self
    {
        $command = sprintf(
            'ps -p %d -o pid=,ppid=,user=,%%cpu=,%%mem=,rss=,etime=,command=',
            $pid
        );
        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($output[0]), 8);
        if ($parts === false || count($parts) < 8) {
            return null;
        }

        [$pid, $ppid, $user, $cpu, $memory, $rss, $elapsed, $command] = $parts;
        return new self(
            pid: (int) $pid,
            ppid: (int) $ppid,
            user: $user,
            cpu: (float) $cpu,
            memory: (float) $memory,
            rssKb: (int) $rss,
            elapsed: $elapsed,
            command: $command,
        );
    }

    /**
     * Resident set size in megabytes.
     */
    public function rssMb(): float
    {
        return $this->rssKb / 1024;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'pid'     => $this->pid,
            'ppid'    => $this->ppid,
            'user'    => $this->user,
            'cpu'     => $this->cpu,
            'memory'  => $this->memory,
            'rss_mb'  => round($this->rssMb(), 1),
            'elapsed' => $this->elapsed,
            'command' => $this->command,
        ];
    }
}
