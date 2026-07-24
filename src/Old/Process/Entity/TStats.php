<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Old\Process\Entity;

final class TStats
{
    public function __construct(
        public int $pid,
        public int $ppid,
        public string $user,
        public float $cpu,
        public float $mem,
        public int $rssKb,
        public string $etime,
        public string $command,
    ) {
    }

    public static function ofPid(int $pid): ?self
    {
        $safeCmd = sprintf(
            'ps -p %d -o pid=,ppid=,user=,%%cpu=,%%mem=,rss=,etime=,command=',
            (int) $pid
        );
        exec($safeCmd, $output, $exitCode);
        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($output[0]), 8);
        if (count($parts) < 8) {
            return null;
        }

        [$pid, $ppid, $user, $cpu, $mem, $rss, $etime, $command] = $parts;
        return new self(
            pid: (int) $pid,
            ppid: (int) $ppid,
            user: $user,
            cpu: (float) $cpu,
            mem: (float) $mem,
            rssKb: (int) $rss,
            etime: $etime,
            command: $command
        );
    }

    public function rssMb(): float
    {
        return $this->rssKb / 1024;
    }
}
