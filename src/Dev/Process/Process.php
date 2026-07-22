<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\K2\Concurrent\Future;
use Flytachi\Winter\K2\Dev\Process\Engine\Engines;
use Flytachi\Winter\K2\Dev\Process\Engine\ProcessEngine;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Thread\Thread;
use Psr\Log\LoggerInterface;

/**
 * Base for a managed, runtime-agnostic process — the skeleton that turns a body
 * into a controllable unit.
 *
 * You write {@see run()}; the framework supplies the runtime (coroutines under
 * Swoole, forks otherwise), concurrency ({@see spawn()}), cooperative
 * cancellation ({@see isRunning()} / {@see sleep()} + {@see InterruptedException}),
 * a signal contract (5 hooks), an activity state ({@see Activity}), guaranteed
 * teardown ({@see onShutdown()}) and lifecycle control from CLI/web.
 *
 * ```
 * class Consumer extends Process
 * {
 *     public function run(): void
 *     {
 *         $ch = $this->rabbit->connect();
 *         while ($this->isRunning()) {
 *             $msg = $ch->get(timeout: 1.0);
 *             if ($msg === null) { continue; }
 *             $this->markBusy();
 *             $this->handle($msg);
 *             $ch->ack($msg);
 *             $this->markIdle();
 *         }
 *         $ch->close();
 *     }
 * }
 * ```
 */
abstract class Process
{
    /** Maximum simultaneous {@see spawn()} tasks; 0 means unlimited. */
    protected int $concurrency = 0;
    /** Seconds to wait for a BUSY unit / in-flight spawns to drain on stop before forcing. 0 = wait forever. */
    protected float $grace = 0.0;
    /** Title shown in `ps` / status; defaults to the short class name. */
    protected ?string $processTitle = null;

    protected LoggerInterface $logger;
    protected int $pid;
    private ProcessEngine $engine;
    private bool $stopping = false;
    private bool $shutdownDone = false;
    private bool $inlineBusy = false;

    // Status store bookkeeping (a bare process owns its record; a daemon worker does not).
    private bool $ownsRecord = false;
    private int $startedAt = 0;
    private ProcessState $state = ProcessState::NEW;
    private ?Activity $writtenActivity = null;

    final public function __construct()
    {
    }

    /**
     * The process body. Runs inside the chosen runtime.
     */
    abstract public function run(): void;

    // -------------------------------------------------------------------------
    // Primitives available to the body
    // -------------------------------------------------------------------------

    /**
     * Keep looping? False once a stop has been requested (signal or {@see requestStop()}).
     */
    final protected function isRunning(): bool
    {
        return $this->engine->running();
    }

    /**
     * Interruptible pause — non-blocking under Swoole. Throws
     * {@see InterruptedException} if an IDLE wait is woken by a stop.
     */
    final protected function sleep(float $seconds): void
    {
        $this->engine->sleep($seconds);
    }

    /**
     * Dispatches a task concurrently (coroutine under Swoole, fork otherwise),
     * capped by {@see $concurrency}. The process always waits for its spawns to
     * finish before exiting; while any is in flight the process is BUSY.
     */
    final protected function spawn(callable $task): Future
    {
        return $this->engine->spawn($task);
    }

    /**
     * Requests a graceful stop of this process from inside the body — the
     * cooperative equivalent of receiving SIGTERM.
     */
    final protected function requestStop(): void
    {
        // Do not abort an inline BUSY unit; wake only an IDLE wait.
        $this->engine->requestStop(!$this->inlineBusy);
    }

    /**
     * Marks the start of an inline unit of work (no {@see spawn()}). Keeps the
     * process BUSY so it is not interrupted mid-unit and not scaled down.
     */
    final protected function markBusy(): void
    {
        $this->inlineBusy = true;
    }

    /**
     * Marks the end of an inline unit of work.
     */
    final protected function markIdle(): void
    {
        $this->inlineBusy = false;
    }

    /**
     * Current activity: BUSY while an inline unit is marked or any spawn is in
     * flight, IDLE otherwise.
     */
    final protected function activity(): Activity
    {
        return $this->inlineBusy || $this->engine->inFlight() > 0
            ? Activity::BUSY
            : Activity::IDLE;
    }

    // -------------------------------------------------------------------------
    // Signal hooks — override to react to a specific signal
    // -------------------------------------------------------------------------

    /** SIGTERM — stop is guaranteed; override only to react. */
    protected function onTerminate(): void
    {
    }

    /** SIGINT — Ctrl-C; stop is guaranteed. */
    protected function onInterrupt(): void
    {
    }

    /** SIGHUP — reload configuration. Does NOT stop by default. */
    protected function onReload(): void
    {
    }

    /** SIGUSR1 — a user-defined action (e.g. reopen log files). */
    protected function onUser1(): void
    {
    }

    /** SIGUSR2 — a user-defined action (e.g. dump stats, toggle debug). */
    protected function onUser2(): void
    {
    }

    /** Guaranteed teardown — runs on every exit path (graceful, forced, fatal). */
    protected function onShutdown(): void
    {
    }

    // -------------------------------------------------------------------------
    // Control surface (CLI / web)
    // -------------------------------------------------------------------------

    /**
     * Runs the process in the foreground, registering it in the store so
     * {@see status()} / {@see stop()} reach it from another terminal.
     */
    public static function start(): void
    {
        /** @var static $self */
        $self = Container::getInstance()->make(static::class);
        $self->boot();
    }

    /**
     * Launches the process detached in the background and returns its PID.
     *
     * @param string|null $output '/dev/null' (default) or a file path for the child's stdio.
     */
    final public static function dispatch(?string $output = '/dev/null'): int
    {
        return new Thread(
            new ProcessRunnable(static::class),
            'process',
            new \ReflectionClass(static::class)->getShortName(),
        )->start(outputTarget: $output, detached: true);
    }

    /**
     * Current status, or null when the process is not running.
     *
     * @param bool $usage Attach live resource usage (CPU/memory via `ps`).
     */
    final public static function status(bool $usage = false): ?ProcessStatus
    {
        try {
            $store = static::store();
            $key = static::key();
            /** @var ?ProcessStatus $status */
            $status = $store->read($key);
            if (!$status) {
                return null;
            }
            // Drop a stale record whose process is gone (e.g. a forced exit).
            if (!posix_kill($status->pid, 0)) {
                $store->del($key);
                return null;
            }
            if ($usage) {
                $status->usage = ResourceUsage::ofPid($status->pid);
            }
            return $status;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Sends a graceful stop signal (SIGTERM). Returns false when nothing is running.
     */
    final public static function stop(): bool
    {
        $status = static::status();
        if (!$status) {
            return false;
        }
        return posix_kill($status->pid, SIGTERM);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function boot(): void
    {
        $this->prepareWorker();
        $this->ownsRecord = true;
        $this->writeStatus();

        try {
            $this->runBody();
        } finally {
            static::store()->del(static::key());
        }
    }

    /**
     * Worker entry point used by a {@see Daemon} supervisor: sets up the runtime
     * and runs the body, without owning the store record (the supervisor owns it).
     *
     * @internal Not for application code — calling it re-enters the engine.
     */
    protected function runWorker(): void
    {
        $this->prepareWorker();
        $this->runBody();
    }

    private function prepareWorker(): void
    {
        $this->pid = getmypid();
        $this->logger = LoggerFactory::getLogger(static::class);
        $this->startedAt = time();
        $this->state = ProcessState::RUNNING;
        $this->applyProcessTitle();

        // Fatal backstop for onShutdown; the explicit calls below cover normal paths.
        register_shutdown_function(fn() => $this->invokeShutdown());

        $this->engine = Engines::common($this->concurrency, $this->grace);
    }

    private function runBody(): void
    {
        try {
            $this->engine->enter(
                fn() => $this->run(),
                [
                    SIGTERM => fn() => $this->onStopSignal(fn() => $this->onTerminate()),
                    SIGINT => fn() => $this->onStopSignal(fn() => $this->onInterrupt()),
                    SIGHUP => fn() => $this->onReload(),
                    SIGUSR1 => fn() => $this->onUser1(),
                    SIGUSR2 => fn() => $this->onUser2(),
                ],
                fn() => $this->invokeShutdown(),
                fn() => $this->flushStatus(),
            );
        } finally {
            $this->invokeShutdown();
        }
    }

    /**
     * First stop signal begins a graceful stop and runs the hook; further stop
     * signals are ignored (force is the grace timer or an external SIGKILL).
     */
    private function onStopSignal(callable $hook): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        $this->state = ProcessState::STOPPING;
        $this->requestStop();
        $this->writeStatus();
        $hook();
    }

    /**
     * Runs {@see onShutdown()} exactly once, on whichever exit path reaches it.
     */
    private function invokeShutdown(): void
    {
        if ($this->shutdownDone) {
            return;
        }
        $this->shutdownDone = true;
        try {
            $this->onShutdown();
        } catch (\Throwable $e) {
            $this->logger->error('onShutdown() failed: ' . $e->getMessage());
        }
    }

    private function applyProcessTitle(): void
    {
        if (!function_exists('cli_set_process_title')) {
            return;
        }
        $title = $this->processTitle ?? new \ReflectionClass(static::class)->getShortName();
        @cli_set_process_title('winter-process: ' . $title);
    }

    // -------------------------------------------------------------------------
    // Status store — in-memory authoritative, throttled + deduped write
    // -------------------------------------------------------------------------

    /**
     * Called on the heartbeat (~1s): persists the record only when the activity
     * actually changed, so a per-message BUSY/IDLE flip never storms the disk. A
     * no-op for a daemon worker (the supervisor owns the record).
     */
    private function flushStatus(): void
    {
        if (!$this->ownsRecord) {
            return;
        }
        if ($this->activity() === $this->writtenActivity) {
            return;
        }
        $this->writeStatus();
    }

    private function writeStatus(): void
    {
        if (!$this->ownsRecord) {
            return;
        }
        $activity = $this->activity();
        try {
            static::store()->write(static::key(), new ProcessStatus(
                pid: $this->pid,
                className: static::class,
                state: $this->state,
                activity: $activity,
                startedAt: $this->startedAt,
                concurrency: $this->concurrency,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Status write failed: ' . $e->getMessage());
            return;
        }
        $this->writtenActivity = $activity;
    }

    final protected static function key(): string
    {
        return hash('xxh64', static::class);
    }

    final protected static function store(): FileStorage
    {
        return (new ProcessStore(static::class))->main();
    }
}
