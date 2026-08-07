<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Engine;

use Flytachi\Winter\Kernel\Concurrent\Future;

/**
 * Runtime backend that carries a {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process}
 * body.
 *
 * The engine hides the difference between runtimes so the process body is
 * written once: under Swoole tasks become coroutines and pauses are
 * non-blocking; without Swoole tasks become forked children and pauses block
 * the single process. The contract stays identical either way — mirroring how
 * {@see \Flytachi\Winter\Kernel\Concurrent\ExecutorService} keeps one surface over
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
     * @param callable|null $onForceExit Run just before the grace timer forces the process down.
     * @param callable|null $onHeartbeat Run about once a second while the body runs (e.g. to flush status).
     */
    public function enter(
        callable $body,
        array $signals = [],
        ?callable $onForceExit = null,
        ?callable $onHeartbeat = null,
    ): void;

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
     * Pauses the body without blocking sibling tasks under Swoole. Throws
     * {@see \Flytachi\Winter\Kernel\Process\InterruptedException} once if the body
     * was interrupted (an IDLE wait woken by a stop request).
     *
     * @param float $seconds Seconds to pause.
     */
    public function sleep(float $seconds): void;

    /**
     * Returns false once a stop has been requested.
     */
    public function running(): bool;

    /**
     * Requests a graceful stop, flipping {@see running()} to false.
     *
     * @param bool $interrupt Wake a blocked (IDLE) body so it unwinds at once.
     *                        Pass false while an inline unit is BUSY so it is not
     *                        aborted mid-work.
     */
    public function requestStop(bool $interrupt): void;

    /**
     * Number of dispatched tasks that have not settled yet.
     */
    public function inFlight(): int;
}
