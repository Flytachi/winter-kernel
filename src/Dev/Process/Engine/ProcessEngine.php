<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process\Engine;

use Flytachi\Winter\K2\Concurrent\Future;

/**
 * Runtime backend that carries a {@see \Flytachi\Winter\K2\Dev\Process\Process}
 * body.
 *
 * The engine hides the difference between runtimes so the process body is
 * written once: under Swoole tasks become coroutines and pauses are
 * non-blocking; without Swoole tasks become forked children and pauses block
 * the single process. The contract stays identical either way — mirroring how
 * {@see \Flytachi\Winter\K2\Concurrent\ExecutorService} keeps one surface over
 * several backends.
 */
interface ProcessEngine
{
    /**
     * Establishes the runtime context, installs the signal handlers, runs the
     * body, then drains outstanding tasks before returning.
     *
     * @param callable $body The process body (its `run()` method).
     * @param array<int, callable> $signals Map of signal number to handler, installed with the runtime-appropriate mechanism.
     */
    public function enter(callable $body, array $signals = []): void;

    /**
     * Dispatches a task concurrently, capped by the configured concurrency.
     *
     * When the cap is reached the call applies back-pressure: it suspends the
     * caller (Swoole) or blocks it (fork) until a slot frees up.
     *
     * @param callable $task Task to run.
     */
    public function spawn(callable $task): Future;

    /**
     * Pauses the body without blocking sibling tasks under Swoole.
     *
     * @param float $seconds Seconds to pause.
     */
    public function sleep(float $seconds): void;

    /**
     * Returns false once a stop signal (SIGTERM/SIGINT) has been received.
     */
    public function running(): bool;

    /**
     * Requests a graceful stop, flipping {@see running()} to false.
     */
    public function requestStop(): void;

    /**
     * Number of dispatched tasks that have not settled yet.
     */
    public function inFlight(): int;
}
