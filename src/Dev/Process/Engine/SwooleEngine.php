<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process\Engine;

use Flytachi\Winter\K2\Concurrent\Executors;
use Flytachi\Winter\K2\Concurrent\Future;
use Flytachi\Winter\K2\Dev\Process\InterruptedException;

/**
 * Coroutine backend.
 *
 * The body runs inside {@see \Swoole\Coroutine\run()}, so `spawn()` yields real
 * concurrency and `sleep()` never blocks the process. Cancellation is Java-like:
 * a stop request cancels the body coroutine, so a blocked `sleep()` wakes at once
 * and throws {@see InterruptedException} instead of running to completion. A grace
 * timer force-exits if the body ignores the request and keeps yielding.
 */
final class SwooleEngine implements ProcessEngine
{
    private bool $stop = false;
    private int $inFlight = 0;
    private ?\Swoole\Coroutine\Channel $semaphore = null;
    private ?int $bodyCid = null;
    private ?int $graceTimerId = null;
    private ?int $heartbeatTimerId = null;
    private bool $interruptDelivered = false;
    /** @var callable|null */
    private $onForceExit = null;

    /**
     * @param int $concurrency Maximum simultaneous tasks; 0 means unlimited.
     * @param float $grace Seconds to wait after a stop request before forcing exit; 0 disables.
     */
    public function __construct(
        private readonly int $concurrency,
        private readonly float $grace,
    ) {
    }

    public function enter(
        callable $body,
        array $signals = [],
        ?callable $onForceExit = null,
        ?callable $onHeartbeat = null,
    ): void {
        $error = null;
        $this->onForceExit = $onForceExit;

        \Swoole\Coroutine\run(function () use ($body, $signals, $onHeartbeat, &$error): void {
            $this->bodyCid = \Swoole\Coroutine::getCid();

            if ($this->concurrency > 0) {
                $this->semaphore = new \Swoole\Coroutine\Channel($this->concurrency);
                for ($i = 0; $i < $this->concurrency; $i++) {
                    $this->semaphore->push(true);
                }
            }

            // A broken pipe must not kill a long-lived process; handle EPIPE in code.
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGPIPE, SIG_IGN);
            }
            foreach ($signals as $signo => $handler) {
                \Swoole\Process::signal($signo, $handler);
            }
            if ($onHeartbeat !== null) {
                $this->heartbeatTimerId = \Swoole\Timer::tick(1000, static fn() => $onHeartbeat());
            }

            try {
                $body();
                // Drain in-flight tasks on the normal path; skip when cancelled
                // (Coroutine\run drains the rest) to avoid busy-waiting.
                while ($this->inFlight > 0 && !\Swoole\Coroutine::isCanceled()) {
                    \Swoole\Coroutine::sleep(0.01);
                }
            } catch (InterruptedException) {
                // Stop requested mid-block: unwind cleanly.
            } catch (\Throwable $e) {
                $error = $e;
            } finally {
                // Drop timers so a graceful exit is not held back once the body is done.
                $this->disarmGrace();
                if ($this->heartbeatTimerId !== null) {
                    \Swoole\Timer::clear($this->heartbeatTimerId);
                    $this->heartbeatTimerId = null;
                }
            }
        });

        if ($error !== null) {
            throw $error;
        }
    }

    public function spawn(callable $task): Future
    {
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
        // Throw only if this coroutine was cancelled (an IDLE wait woken by a stop).
        // A BUSY unit is not cancelled, so its sleeps return normally.
        if (\Swoole\Coroutine::isCanceled() && !$this->interruptDelivered) {
            $this->interruptDelivered = true;
            throw new InterruptedException();
        }
    }

    public function running(): bool
    {
        return !$this->stop;
    }

    public function requestStop(bool $interrupt): void
    {
        if ($this->stop) {
            return;
        }
        $this->stop = true;

        // Wake an IDLE body blocked in an interruptible point; leave a BUSY unit
        // to finish (do not cancel it).
        if ($interrupt && $this->bodyCid !== null) {
            \Swoole\Coroutine::cancel($this->bodyCid);
        }

        // Backstop: force exit if the body keeps running past the grace window.
        if ($this->graceTimerId === null && $this->grace > 0) {
            $this->graceTimerId = \Swoole\Timer::after(
                (int) ($this->grace * 1000),
                function (): void {
                    if ($this->onForceExit !== null) {
                        ($this->onForceExit)();
                    }
                    exit(1);
                }
            );
        }
    }

    public function inFlight(): int
    {
        return $this->inFlight;
    }

    /**
     * Cancels the grace timer, if armed, so a finished process exits at once.
     */
    private function disarmGrace(): void
    {
        if ($this->graceTimerId !== null) {
            \Swoole\Timer::clear($this->graceTimerId);
            $this->graceTimerId = null;
        }
    }
}
