<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Engine;

use Flytachi\Winter\K2\Concurrent\CompletableFuture;
use Flytachi\Winter\K2\Concurrent\Future;
use Flytachi\Winter\K2\Process\InterruptedException;

/**
 * Fork backend for runtimes without Swoole.
 *
 * Each `spawn()` forks a child process (fire-and-forget — a child cannot return a
 * value to the parent). `sleep()` is interruptible: it wakes in small steps and
 * throws {@see InterruptedException} once a stop is requested, matching the
 * coroutine backend. The grace deadline is enforced with `SIGALRM` via
 * `pcntl_alarm()`, which interrupts even a native blocking call.
 */
final class SyncEngine implements ProcessEngine
{
    /** Step size for interruptible sleep, in seconds. */
    private const float SLEEP_STEP = 0.1;

    private bool $stop = false;
    private bool $interruptRequested = false;
    private bool $interruptDelivered = false;
    private readonly bool $hasPcntl;
    /** @var callable|null */
    private $onForceExit = null;
    /** @var callable|null */
    private $onHeartbeat = null;
    private float $lastHeartbeat = 0.0;
    /** @var array<int, true> Live child PIDs. */
    private array $children = [];

    /**
     * @param int $concurrency Maximum simultaneous children; 0 means unlimited.
     * @param float $grace Seconds to wait after a stop request before forcing exit; 0 disables.
     */
    public function __construct(
        private readonly int $concurrency,
        private readonly float $grace,
    ) {
        $this->hasPcntl = extension_loaded('pcntl');
    }

    /**
     * {@inheritDoc}
     */
    public function enter(
        callable $body,
        array $signals = [],
        ?callable $onForceExit = null,
        ?callable $onHeartbeat = null,
    ): void {
        $this->onForceExit = $onForceExit;
        $this->onHeartbeat = $onHeartbeat;

        if ($this->hasPcntl) {
            pcntl_async_signals(true);
            pcntl_signal(SIGPIPE, SIG_IGN);
            foreach ($signals as $signo => $handler) {
                pcntl_signal($signo, $handler);
            }
        }

        try {
            $body();
        } catch (InterruptedException) {
            // Stop requested mid-block: unwind cleanly.
        }

        $this->waitAll();
    }

    /**
     * {@inheritDoc}
     */
    public function spawn(callable $task): Future
    {
        if (!$this->hasPcntl) {
            return CompletableFuture::completedFuture($task());
        }

        $this->reap();
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

    /**
     * {@inheritDoc}
     */
    public function sleep(float $seconds): void
    {
        $remaining = $seconds;
        while ($remaining > 0) {
            $step = min($remaining, self::SLEEP_STEP);
            usleep((int) ($step * 1_000_000));
            if ($this->hasPcntl) {
                pcntl_signal_dispatch();
            }
            // Throw only when an interrupt was requested (an IDLE wait); a BUSY unit
            // is stopped cooperatively and its sleeps return normally.
            if ($this->interruptRequested && !$this->interruptDelivered) {
                $this->interruptDelivered = true;
                throw new InterruptedException();
            }
            $this->heartbeat();
            $remaining -= $step;
        }
    }

    /**
     * Fires the heartbeat callback at most once a second (the sync backend has no
     * event loop, so this is the periodic hook available).
     */
    private function heartbeat(): void
    {
        if ($this->onHeartbeat === null) {
            return;
        }
        $now = microtime(true);
        if (($now - $this->lastHeartbeat) >= 1.0) {
            $this->lastHeartbeat = $now;
            ($this->onHeartbeat)();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function running(): bool
    {
        return !$this->stop;
    }

    /**
     * {@inheritDoc}
     */
    public function requestStop(bool $interrupt): void
    {
        if ($this->stop) {
            return;
        }
        $this->stop = true;
        if ($interrupt) {
            $this->interruptRequested = true;
        }

        // Backstop: SIGALRM force-exits after the grace window, interrupting even a
        // native blocking call.
        if ($this->hasPcntl && $this->grace > 0) {
            pcntl_signal(SIGALRM, function (): void {
                if ($this->onForceExit !== null) {
                    ($this->onForceExit)();
                }
                exit(1);
            });
            pcntl_alarm((int) ceil($this->grace));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function inFlight(): int
    {
        $this->reap();
        return count($this->children);
    }

    /**
     * Harvests any finished spawn children without blocking (avoids zombies).
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
     * Blocks until every spawn child has finished — the structured-concurrency
     * drain that runs after the body returns.
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
