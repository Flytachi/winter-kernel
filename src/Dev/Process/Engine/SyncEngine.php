<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process\Engine;

use Flytachi\Winter\K2\Concurrent\CompletableFuture;
use Flytachi\Winter\K2\Concurrent\Future;

/**
 * Fork backend for runtimes without Swoole.
 *
 * Each `spawn()` forks a child process, matching the existing daemon model.
 * Because a child cannot write a return value back to the parent, the future is
 * a settled placeholder — fork tasks are fire-and-forget. When `pcntl` is
 * unavailable the task simply runs inline. Concurrency is capped by blocking on
 * `pcntl_wait()` once the cap is reached.
 */
final class SyncEngine implements ProcessEngine
{
    private bool $stop = false;
    private readonly bool $hasPcntl;
    /** @var array<int, true> Live child PIDs. */
    private array $children = [];

    /**
     * @param int $concurrency Maximum simultaneous children; 0 means unlimited.
     */
    public function __construct(private readonly int $concurrency)
    {
        $this->hasPcntl = extension_loaded('pcntl');
    }

    public function enter(callable $body, array $signals = []): void
    {
        if ($this->hasPcntl) {
            pcntl_async_signals(true);
            foreach ($signals as $signo => $handler) {
                pcntl_signal($signo, $handler);
            }
        }

        $body();
        $this->waitAll();
    }

    public function spawn(callable $task): Future
    {
        if (!$this->hasPcntl) {
            return CompletableFuture::completedFuture($task());
        }

        $this->reap();
        // Back-pressure: block until a slot frees up.
        while ($this->concurrency > 0 && count($this->children) >= $this->concurrency) {
            $pid = pcntl_wait($status);
            if ($pid > 0) {
                unset($this->children[$pid]);
            }
        }

        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $task();
            } catch (\Throwable) {
                // Isolated in the child; nothing to propagate to the parent.
            } finally {
                exit(0);
            }
        }

        if ($pid > 0) {
            $this->children[$pid] = true;
        }

        return CompletableFuture::completedFuture(null);
    }

    public function sleep(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
        if ($this->hasPcntl) {
            pcntl_signal_dispatch();
        }
    }

    public function running(): bool
    {
        return !$this->stop;
    }

    public function requestStop(): void
    {
        $this->stop = true;
    }

    public function inFlight(): int
    {
        $this->reap();
        return count($this->children);
    }

    /**
     * Reaps finished children without blocking.
     */
    private function reap(): void
    {
        if (!$this->hasPcntl) {
            return;
        }
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            unset($this->children[$pid]);
        }
    }

    /**
     * Blocks until every child has exited.
     */
    private function waitAll(): void
    {
        if (!$this->hasPcntl) {
            return;
        }
        foreach (array_keys($this->children) as $pid) {
            pcntl_waitpid($pid, $status);
            unset($this->children[$pid]);
        }
    }
}
