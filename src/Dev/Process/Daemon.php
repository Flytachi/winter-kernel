<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\K2\Dev\Process\Supervisor\Supervisor;
use Flytachi\Winter\Logger\LoggerFactory;

/**
 * A supervised, self-healing {@see Process}.
 *
 * `start()` launches a supervisor that forks {@see $replicas} workers and keeps
 * them alive per {@see $restart}. A crashed worker is restarted with
 * exponential back-off; a clean exit is respected under {@see RestartPolicy::ON_FAILURE}.
 * The body ({@see run()}), concurrency and the coroutine/fork split are inherited
 * unchanged from {@see Process} — a daemon is a process that is kept running.
 *
 * ```
 * class BillingConsumer extends Daemon
 * {
 *     protected int $replicas = 1;
 *     protected RestartPolicy $restart = RestartPolicy::ON_FAILURE;
 *     protected int $maxRestarts = 0;      // 0 = unlimited
 *     protected float $backoff = 1.0;      // base seconds, exponential
 *     protected int $concurrency = 20;
 *
 *     public function run(): void
 *     {
 *         $ch = $this->rabbit->connect();
 *         while ($this->isRunning()) {
 *             $msg = $ch->get();
 *             if ($msg === null) { $this->sleep(0.2); continue; }
 *             $this->spawn(fn() => $this->handle($msg));
 *         }
 *         $ch->close();
 *     }
 * }
 * ```
 */
abstract class Daemon extends Process
{
    /** Number of identical workers to keep running. */
    protected int $replicas = 1;
    /** When to restart a worker after it exits. */
    protected RestartPolicy $restart = RestartPolicy::ON_FAILURE;
    /** Give up after this many restarts (0 = unlimited). */
    protected int $maxRestarts = 0;
    /** Base seconds for exponential back-off between restarts. */
    protected float $backoff = 1.0;

    public function replicas(): int
    {
        return max(1, $this->replicas);
    }

    public function restartPolicy(): RestartPolicy
    {
        return $this->restart;
    }

    public function maxRestarts(): int
    {
        return $this->maxRestarts;
    }

    public function backoffBase(): float
    {
        return $this->backoff;
    }

    /**
     * Launches the supervisor in the foreground, registering it in the store so
     * {@see status()} / {@see stop()} reach it from another terminal.
     */
    final public static function start(): void
    {
        /** @var static $self */
        $self = Container::getInstance()->make(static::class);
        $self->supervise();
    }

    private function supervise(): void
    {
        $this->pid = getmypid();
        $this->logger = LoggerFactory::getLogger(static::class);

        $store = static::store();
        $key = static::key();
        $startedAt = time();

        $write = function (ProcessState $state, int $restarts, array $workers) use ($store, $key, $startedAt): void {
            $store->write($key, new ProcessStatus(
                pid: $this->pid,
                className: static::class,
                state: $state,
                activity: $workers === [] ? Activity::IDLE : Activity::BUSY,
                startedAt: $startedAt,
                concurrency: $this->concurrency,
                restarts: $restarts,
                workers: $workers,
            ));
        };

        $write(ProcessState::RUNNING, 0, []);

        // Backstop the finally below against a forced/fatal exit that skips it.
        register_shutdown_function(static fn() => $store->del($key));

        try {
            $final = (new Supervisor())->run(
                $this,
                fn() => $this->runWorker(),
                fn(int $restarts, array $workers) => $write(ProcessState::RUNNING, $restarts, $workers),
            );

            if ($final === ProcessState::FAILED) {
                $this->logger->critical('Daemon reached maxRestarts; giving up.');
            }
        } catch (\Throwable $e) {
            $this->logger->critical(
                $e->getMessage()
                . (env('DEBUG', false) ? "\n" . $e->getTraceAsString() : '')
            );
        } finally {
            $store->del($key);
        }
    }
}
