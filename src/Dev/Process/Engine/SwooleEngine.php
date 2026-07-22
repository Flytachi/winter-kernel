<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process\Engine;

use Flytachi\Winter\K2\Concurrent\Executors;
use Flytachi\Winter\K2\Concurrent\Future;

/**
 * Coroutine backend.
 *
 * The body runs inside {@see \Swoole\Coroutine\run()}, so `spawn()` yields real
 * concurrency, `sleep()` never blocks the process, and every task borrows its
 * own connection from the shared PPA pool. Concurrency is capped by a counting
 * semaphore built on a {@see \Swoole\Coroutine\Channel}: acquiring suspends the
 * caller when the cap is reached, which is exactly the back-pressure a producer
 * loop needs.
 */
final class SwooleEngine implements ProcessEngine
{
    private bool $stop = false;
    private int $inFlight = 0;
    private ?\Swoole\Coroutine\Channel $semaphore = null;

    /**
     * @param int $concurrency Maximum simultaneous tasks; 0 means unlimited.
     */
    public function __construct(private readonly int $concurrency)
    {
    }

    public function enter(callable $body, array $signals = []): void
    {
        $error = null;

        \Swoole\Coroutine\run(function () use ($body, $signals, &$error): void {
            if ($this->concurrency > 0) {
                $this->semaphore = new \Swoole\Coroutine\Channel($this->concurrency);
                for ($i = 0; $i < $this->concurrency; $i++) {
                    $this->semaphore->push(true);
                }
            }

            foreach ($signals as $signo => $handler) {
                \Swoole\Process::signal($signo, $handler);
            }

            try {
                $body();
            } catch (\Throwable $e) {
                // Swoole may swallow a throwable escaping the top coroutine; capture
                // it and rethrow outside so a supervisor sees a non-zero exit.
                $error = $e;
                return;
            }

            // Let tasks already in flight finish before the process exits.
            while ($this->inFlight > 0) {
                \Swoole\Coroutine::sleep(0.01);
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }

    public function spawn(callable $task): Future
    {
        // Acquire a slot; suspends the caller when the cap is reached.
        $this->semaphore?->pop();
        $this->inFlight++;

        $wrapped = function () use ($task): mixed {
            try {
                return $task();
            } finally {
                $this->inFlight--;
                $this->semaphore?->push(true);
            }
        };

        return Executors::common()->submit($wrapped);
    }

    public function sleep(float $seconds): void
    {
        \Swoole\Coroutine::sleep($seconds);
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
        return $this->inFlight;
    }
}
