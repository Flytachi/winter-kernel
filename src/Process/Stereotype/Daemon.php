<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Stereotype;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Process\Daemon\DaemonConfigException;
use Flytachi\Winter\Kernel\Process\Daemon\DaemonStatus;
use Flytachi\Winter\Kernel\Process\Daemon\RestartPolicy;
use Flytachi\Winter\Kernel\Process\Daemon\ScalingPolicy;
use Flytachi\Winter\Kernel\Process\Daemon\SupervisesFleet;
use Flytachi\Winter\Kernel\Process\Internal\SingletonLock;
use Flytachi\Winter\Kernel\Process\ProcessState;
use Flytachi\Winter\Kernel\Process\ProcessStatus;
use Flytachi\Winter\Logger\LoggerFactory;

/**
 * A supervised, self-healing, autoscaling fleet of workers.
 *
 * A daemon is a manager, not a body: `start()` launches a master supervisor that
 * forks {@see $replicas} workers and keeps the fleet at the size
 * {@see desiredReplicas()} asks for, restarting crashes per {@see restart()} and
 * damping size changes per {@see scaling()}. The manager and the unit of work are
 * separate concerns — like an executor and its task.
 *
 * The worker body is given ONE of two ways (priority: {@see workerRun()} first):
 * ```
 * // inline — the daemon is also the worker
 * class Emails extends Daemon {
 *     protected int $replicas = 3;
 *     protected function workerRun(): void {
 *         while ($this->isRunning()) {
 *             $this->markBusy();
 *             // ... work ...
 *             $this->markIdle();
 *         }
 *     }
 * }
 *
 * // external — supervise a reusable Process class
 * class Emails extends Daemon {
 *     protected int $replicas = 3;
 *     protected ?string $workerClass = SendProcess::class;
 * }
 * ```
 *
 * The whole process tree, forking/reaping, restart, drain and singleton lock are
 * handled by the fleet supervision ({@see SupervisesFleet}); you write only
 * config + body (+ optional autoscaling and hooks).
 */
abstract class Daemon extends Process
{
    use SingletonLock;
    use SupervisesFleet;

    /** Baseline number of workers to keep running (the default scale target). */
    protected int $replicas = 1;
    /** Worker Process class to supervise when {@see workerRun()} is not defined. */
    protected ?string $workerClass = null;
    /**
     * How long the master waits for a worker to drain on stop / scale-down before
     * SIGKILL, in seconds. Overrides {@see Process::$grace} (0) with a bounded,
     * deploy-safe default: a stuck worker can never hang the whole fleet's
     * shutdown — mirroring Kubernetes' 30s terminationGracePeriodSeconds. Set to
     * 0 to wait forever (never cut off in-flight work).
     */
    protected float $grace = 30.0;
    /**
     * Watchdog: kill and restart a worker whose heartbeat has been silent this
     * long, in seconds. Catches a wedged worker (deadlock, hung I/O, a boot that
     * never finishes) that a plain liveness check misses. 0 disables it (the
     * default). Under the fork runtime, set it longer than your longest
     * non-yielding unit, or call {@see touch()} inside such a unit, to avoid
     * killing a healthy-but-busy worker.
     */
    protected float $livenessTimeout = 0.0;

    // -------------------------------------------------------------------------
    // Overridable policy (all optional — sane defaults apply)
    // -------------------------------------------------------------------------

    /**
     * How many workers should be running right now. Override for autoscaling
     * (e.g. from queue depth); the supervisor damps the value per {@see scaling()}.
     */
    protected function desiredReplicas(): int
    {
        return $this->replicas();
    }

    /**
     * Scaling damping policy (stability over speed). Override to tune.
     */
    protected function scaling(): ScalingPolicy
    {
        return ScalingPolicy::default();
    }

    /**
     * Restart policy for an unexpectedly dead worker. Override to tune.
     */
    protected function restart(): RestartPolicy
    {
        return RestartPolicy::default();
    }

    /**
     * Inline worker body. Define it to run the daemon itself as the worker; if it
     * is not defined, {@see $workerClass} is supervised instead.
     */
    protected function workerRun(): void
    {
        throw new DaemonConfigException(
            static::class . ': workerRun() is not defined and $workerClass is not set.'
        );
    }

    // -------------------------------------------------------------------------
    // Master lifecycle hooks (all optional)
    // -------------------------------------------------------------------------

    /** A worker was forked into a slot. */
    protected function onWorkerStart(int $slot, int $pid): void
    {
    }

    /** A worker exited ($crashed = a non-zero/abnormal exit of a live worker). */
    protected function onWorkerExit(int $slot, int $pid, bool $crashed): void
    {
    }

    /** The fleet size changed. */
    protected function onScale(int $from, int $to): void
    {
    }

    /** Periodic master callback (about once per scaleInterval) — poll metrics here. */
    protected function tick(): void
    {
    }

    // -------------------------------------------------------------------------
    // Worker body wiring — the engine calls run(), which delegates to workerRun()
    // -------------------------------------------------------------------------

    /**
     * Satisfies the {@see Process} body contract by delegating to
     * {@see workerRun()}. Final — a daemon defines its inline body in
     * `workerRun()` (or supervises {@see $workerClass}), never by overriding run().
     */
    final public function run(): void
    {
        $this->workerRun();
    }

    // -------------------------------------------------------------------------
    // Control surface
    // -------------------------------------------------------------------------

    /**
     * Launches the supervisor in the foreground, registering it in the store so
     * {@see status()} / {@see stop()} reach it from another terminal.
     */
    final public static function start(): void
    {
        static::ensureNotRunning();

        /** @var static $self */
        $self = Container::getInstance()->make(static::class);
        $self->supervise();
    }

    // -------------------------------------------------------------------------
    // Internal surface consumed by the SupervisesFleet trait (all private)
    // -------------------------------------------------------------------------

    /** @internal */
    private function replicas(): int
    {
        return max(1, $this->replicas);
    }

    /** @internal */
    private function computeDesired(): int
    {
        return max(0, $this->desiredReplicas());
    }

    /** @internal */
    private function scalingPolicy(): ScalingPolicy
    {
        return $this->scaling();
    }

    /** @internal */
    private function restartPolicy(): RestartPolicy
    {
        return $this->restart();
    }

    /** @internal Master's drain-deadline budget for a stopping/retiring worker. */
    private function graceSeconds(): float
    {
        return max(0.0, $this->grace);
    }

    /** @internal Watchdog silence threshold (0 = disabled). */
    private function livenessTimeout(): float
    {
        return max(0.0, $this->livenessTimeout);
    }

    /**
     * Child side: resolves the worker body and runs it in the forked worker.
     *
     * @internal Called by the supervisor in the fork child.
     */
    private function bootWorker(int $slot): void
    {
        $title = $this->workerTitle($slot);

        if ($this->definesWorkerRun()) {
            $this->runWorker($slot, $title, static::class);
            return;
        }

        if ($this->workerClass !== null) {
            $worker = Container::getInstance()->make($this->workerClass);
            if (!$worker instanceof Process) {
                throw new DaemonConfigException(
                    static::class . ": \$workerClass {$this->workerClass} must extend Process."
                );
            }
            $worker->runWorker($slot, $title, static::class);
            return;
        }

        throw new DaemonConfigException(
            static::class . ': no worker body — define workerRun() or set $workerClass.'
        );
    }

    /** @internal Best-effort read of a worker's last heartbeat record. */
    private function workerRecord(int $slot): ?ProcessStatus
    {
        try {
            $record = static::store()->read($this->workerRecordKey($slot));
            return $record instanceof ProcessStatus ? $record : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @internal Removes a worker's per-slot heartbeat record. */
    private function clearWorkerRecord(int $slot): void
    {
        try {
            static::store()->del($this->workerRecordKey($slot));
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }

    /** @internal */
    private function fireWorkerStart(int $slot, int $pid): void
    {
        $this->guard(fn() => $this->onWorkerStart($slot, $pid));
    }

    /** @internal */
    private function fireWorkerExit(int $slot, int $pid, bool $crashed): void
    {
        $this->guard(fn() => $this->onWorkerExit($slot, $pid, $crashed));
    }

    /** @internal */
    private function fireScale(int $from, int $to): void
    {
        $this->guard(fn() => $this->onScale($from, $to));
    }

    /** @internal */
    private function fireTick(): void
    {
        $this->guard(fn() => $this->tick());
    }

    /** @internal */
    private function fireReload(): void
    {
        $this->guard(fn() => $this->onReload());
    }

    // -------------------------------------------------------------------------
    // Title
    // -------------------------------------------------------------------------

    /**
     * The master's `ps` title, e.g. `winter-daemon: Emails master`.
     */
    protected function buildProcessTitle(): string
    {
        return 'winter-daemon: ' . $this->titleName() . ' master';
    }

    /**
     * A worker's `ps` title, e.g. `winter-daemon: Emails worker#2`. The number is
     * one-based (slot 0 → worker#1), so the whole tree shares a `winter-daemon:`
     * prefix and can be found — or killed — together.
     */
    private function workerTitle(int $slot): string
    {
        return 'winter-daemon: ' . $this->titleName() . ' worker#' . ($slot + 1);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * The master loop: takes the singleton lock, publishes the {@see DaemonStatus}
     * record, runs the fleet loop ({@see superviseFleet()}), and on exit runs {@see onShutdown()},
     * removes the record and releases the lock.
     */
    private function supervise(): void
    {
        if (!$this->acquireLock()) {
            LoggerFactory::getLogger(static::class)->notice(
                static::class . ' is already running; not starting a second supervisor.'
            );
            return;
        }

        $this->pid = getmypid();
        $this->logger = LoggerFactory::getLogger(static::class);
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($this->buildProcessTitle()); // master title
        }

        $store = static::store();
        $key = static::key();
        $startedAt = time();

        $write = function () use ($store, $key, $startedAt): void {
            $workers = $this->snapshot();
            $busy = false;
            foreach ($workers as $worker) {
                if ($worker->activity === Activity::BUSY) {
                    $busy = true;
                    break;
                }
            }
            try {
                $store->write($key, new DaemonStatus(
                    pid: $this->pid,
                    className: static::class,
                    state: $this->isStopping() ? ProcessState::STOPPING : ProcessState::RUNNING,
                    activity: $busy ? Activity::BUSY : Activity::IDLE,
                    startedAt: $startedAt,
                    concurrency: $this->concurrency,
                    restarts: $this->restartsTotal(),
                    workers: $workers,
                ));
            } catch (\Throwable $e) {
                $this->logger->warning('Daemon status write failed: ' . $e->getMessage());
            }
        };

        $write();
        // Backstop the finally below against a forced/fatal exit that skips it.
        register_shutdown_function(static fn() => $store->del($key));

        try {
            $final = $this->superviseFleet($write);
            if ($final === ProcessState::FAILED) {
                $this->logger->critical('Daemon reached maxRestarts; giving up.');
            }
        } catch (\Throwable $e) {
            $this->logger->critical(
                $e->getMessage()
                . (env('DEBUG', false) ? "\n" . $e->getTraceAsString() : '')
            );
        } finally {
            $this->guard(fn() => $this->onShutdown());
            $store->del($key);
            $this->releaseLock();
        }
    }

    /**
     * Runs a user hook, swallowing (and logging) any exception so a faulty hook
     * can never take the supervisor down.
     */
    private function guard(callable $hook): void
    {
        try {
            $hook();
        } catch (\Throwable $e) {
            $this->logger->error('Daemon hook failed: ' . $e->getMessage());
        }
    }

    /**
     * Whether a subclass overrode {@see workerRun()} — the signal to run the
     * daemon itself as the worker rather than supervising {@see $workerClass}.
     */
    private function definesWorkerRun(): bool
    {
        return (new \ReflectionMethod($this, 'workerRun'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Store key of a worker's per-slot heartbeat record (this daemon's class + slot).
     */
    private function workerRecordKey(int $slot): string
    {
        return hash('xxh64', static::class . '#' . $slot);
    }
}
