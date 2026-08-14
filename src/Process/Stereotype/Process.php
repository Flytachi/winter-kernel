<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Stereotype;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\Concurrent\Future;
use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Process\Engine\Engines;
use Flytachi\Winter\Kernel\Process\Engine\ProcessEngine;
use Flytachi\Winter\Kernel\Process\ForkReset;
use Flytachi\Winter\Kernel\Process\Internal\SingletonLock;
use Flytachi\Winter\Kernel\Process\ProcessAlreadyRunningException;
use Flytachi\Winter\Kernel\Process\ProcessRunnable;
use Flytachi\Winter\Kernel\Process\ProcessState;
use Flytachi\Winter\Kernel\Process\ProcessStatus;
use Flytachi\Winter\Kernel\Process\ProcessStore;
use Flytachi\Winter\Kernel\Process\ResourceUsage;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Thread\Thread;
use Psr\Log\LoggerInterface;

/**
 * Base for a managed, runtime-agnostic process — the skeleton that turns a body
 * into a controllable unit.
 *
 * You write {@see run()}; the framework supplies the runtime (coroutines under
 * Swoole, forks otherwise), concurrency ({@see spawn()}), cooperative
 * cancellation ({@see isRunning()} / {@see sleep()} + {@see \Flytachi\Winter\Kernel\Process\InterruptedException}),
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
 *             // ... process $msg ...
 *             $ch->ack($msg);
 *             $this->markIdle();
 *         }
 *         $ch->close();
 *     }
 * }
 * ```
 *
 * @link https://winterframe.net/docs/processes Process body, lifecycle and control
 */
abstract class Process
{
    use SingletonLock;

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

    // Status store bookkeeping (a bare process owns its record; a daemon worker
    // instead writes a per-slot heartbeat record under its owner daemon's store).
    private bool $ownsRecord = false;
    private int $startedAt = 0;
    private ProcessState $state = ProcessState::NEW;
    private ?Activity $writtenActivity = null;

    // Daemon-worker context, injected by the supervisor before runWorker().
    private ?int $workerSlot = null;
    private ?string $ownerClass = null;
    private ?string $titleOverride = null;
    /** Microtime of the last worker heartbeat write; throttles {@see touch()}. */
    private float $lastHeartbeatAt = 0.0;
    /** Minimum seconds between heartbeat writes (throttle for a tight touch() loop). */
    private const float HEARTBEAT_THROTTLE = 1.0;

    /**
     * Constructed by the framework via the DI container (so `#[Autowired]`
     * dependencies resolve) — never `new`ed by application code. Use the static
     * control surface ({@see start()} / {@see dispatch()}) instead.
     */
    final public function __construct()
    {
    }

    /**
     * The process body — your long-running work. Runs inside the chosen runtime
     * (Swoole coroutines, or a plain process). Loop on {@see isRunning()} so a
     * stop signal can end it cleanly.
     *
     * ```
     * public function run(): void
     * {
     *     while ($this->isRunning()) {
     *         // ... do one unit of work ...
     *         $this->sleep(0.5);
     *     }
     * }
     * ```
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
     * {@see \Flytachi\Winter\Kernel\Process\InterruptedException} if an IDLE wait is woken by a stop.
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
     *
     * This is also where the **request scope ends and a new one begins**. Under HTTP a
     * request is a coroutine and its scope dies with it; a worker has no such boundary —
     * its whole body runs in one coroutine, so a `#[Request]` bean resolved inside would
     * survive every iteration and carry the previous job's state into the next. Verified:
     * four iterations, one object, each seeing what the one before it wrote.
     *
     * Only the body knows where a unit ends, and this call already says so — the activity
     * flag and the scope boundary are two readings of the same event, so the developer
     * gets correct scoping without having to know the scope exists.
     *
     * A body that never calls this has declared no units, and nothing is reset.
     */
    final protected function markBusy(): void
    {
        $this->inlineBusy = true;

        if (Container::isInitialized()) {
            Container::getInstance()->flushRequestScope();
        }
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

    /**
     * Explicit liveness beat for a daemon watchdog. The engine already beats
     * about once a second, which covers a worker that yields (sleep / coroutine
     * I/O). Call {@see touch()} from inside a long, non-yielding unit of work so a
     * heartbeat still lands and the supervisor does not mistake progress for a
     * hang — mainly needed under the fork runtime, where nothing beats while a
     * native blocking call runs. Throttled, so a tight loop is safe; a no-op for
     * a bare process (the watchdog is a daemon concern).
     */
    final protected function touch(): void
    {
        if ($this->workerSlot === null) {
            return;
        }
        if ((microtime(true) - $this->lastHeartbeatAt) >= self::HEARTBEAT_THROTTLE) {
            $this->writeWorkerRecord();
        }
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

    /**
     * Runs in a freshly forked daemon worker, before {@see run()}. Resets
     * inherited fork-unsafe resources — every framework reset registered with
     * {@see ForkReset} runs here (e.g. a DB pool reconnect). Override to reset
     * your own resources (call `parent::afterFork()` first). A bare foreground
     * process is not forked and never runs this.
     */
    protected function afterFork(): void
    {
        ForkReset::runAll();
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
        static::ensureNotRunning();

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
        static::ensureNotRunning();

        return new Thread(
            new ProcessRunnable(static::class),
            'process',
            new \ReflectionClass(static::class)->getShortName(),
        )->start(outputTarget: $output, detached: true);
    }

    /**
     * Refuses to launch a second instance of the same class.
     *
     * @throws ProcessAlreadyRunningException If one is already running.
     */
    protected static function ensureNotRunning(): void
    {
        $info = static::status();
        if ($info !== null) {
            throw new ProcessAlreadyRunningException(
                static::class . " is already running [PID {$info->pid}] (since {$info->getStartedAt()})."
            );
        }
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
            // Liveness via getpgid, not kill(pid, 0): getpgid needs no permission,
            // so a status check from another user (web as `winter`, process as
            // root) cannot mistake a live process for a dead one. And status() is a
            // pure read — it never deletes: a read must have no side effect, or any
            // caller who can query could evict a running process's record. A stale
            // record left by a crash is harmless (this returns null) and is
            // overwritten by the next start().
            if (posix_getpgid($status->pid) === false) {
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

    /**
     * Foreground entry point: takes the singleton lock, registers the status
     * record, runs the body, and guarantees the record and lock are released on
     * every exit path (graceful, forced or fatal).
     */
    private function boot(): void
    {
        // The status check in start()/dispatch() catches the common case; this
        // exclusive lock closes the race between two near-simultaneous launches.
        // A flock is released automatically when the process dies, so it is never
        // left stale — unlike a PID file.
        if (!$this->acquireLock()) {
            LoggerFactory::getLogger(static::class)->notice(
                static::class . ' is already running; not starting a second instance.'
            );
            return;
        }

        $this->prepareWorker();
        $this->ownsRecord = true;
        $this->writeStatus();

        try {
            $this->runBody();
        } finally {
            static::store()->del(static::key());
            $this->releaseLock();
        }
    }

    /**
     * Worker entry point used by a Daemon supervisor: resets inherited resources,
     * sets up the runtime and runs the body. It does not own the main store record
     * (the supervisor does); instead it writes a per-slot heartbeat the supervisor
     * reads for the fleet view.
     *
     * @param int|null $slot Stable fleet slot, or null for a bare worker.
     * @param string|null $title OS process title to apply (the daemon's worker title).
     * @param string|null $ownerClass Owning daemon class whose store holds the per-slot record.
     * @internal Not for application code — calling it re-enters the engine.
     *           Protected (not public) so a daemon can boot an external
     *           {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon::$workerClass}
     *           worker (a sibling Process) without exposing it to the outside.
     */
    protected function runWorker(?int $slot = null, ?string $title = null, ?string $ownerClass = null): void
    {
        $this->workerSlot = $slot;
        $this->titleOverride = $title;
        $this->ownerClass = $ownerClass;
        $this->afterFork();
        $this->prepareWorker();
        $this->runBody();
    }

    /**
     * Wires up the running process — PID, logger, title, the runtime engine and a
     * fatal backstop for {@see onShutdown()} — then writes an initial heartbeat
     * for a daemon worker. Shared by the foreground path and a supervised worker.
     */
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

        // A daemon worker writes its initial heartbeat at once, so the supervisor
        // sees it promote from STARTING to RUNNING without waiting a full tick.
        if ($this->workerSlot !== null) {
            $this->writeStatus();
        }
    }

    /**
     * Runs the body inside the runtime engine with the signal map wired to the
     * hooks (SIGTERM/SIGINT → stop, SIGHUP → reload, SIGUSR1/2 → user), and
     * guarantees {@see onShutdown()} runs once however the body ends.
     */
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

    /**
     * Sets the OS process title shown in `ps` / `htop`, so the process is easy to
     * find and signal. No-op where `cli_set_process_title` is unavailable. Internal
     * — override {@see buildProcessTitle()} / {@see titleName()} to customise it.
     */
    private function applyProcessTitle(): void
    {
        if (!function_exists('cli_set_process_title')) {
            return;
        }
        // A daemon injects the worker title (e.g. `winter-daemon: X worker#2`);
        // a bare process builds its own.
        @cli_set_process_title($this->titleOverride ?? $this->buildProcessTitle());
    }

    /**
     * The display name for this process, without the runtime prefix: the explicit
     * {@see $processTitle} when set, otherwise the class short name.
     */
    protected function titleName(): string
    {
        return $this->processTitle ?? new \ReflectionClass(static::class)->getShortName();
    }

    /**
     * The full OS process title. Overridable so a {@see Daemon} can label its
     * master and numbered workers distinctly (e.g. `winter-daemon: X worker#2`).
     */
    protected function buildProcessTitle(): string
    {
        return 'winter-process: ' . $this->titleName();
    }

    // -------------------------------------------------------------------------
    // Status store — in-memory authoritative, throttled + deduped write
    // -------------------------------------------------------------------------

    /**
     * Called on the heartbeat (~1s): persists the record only when the activity
     * actually changed, so a per-message BUSY/IDLE flip never storms the disk.
     * Routes to the bare-process record or the daemon-worker per-slot record.
     */
    private function flushStatus(): void
    {
        // A daemon worker beats every tick (monotonic liveness for the watchdog),
        // regardless of whether its activity changed. A bare process dedups by
        // activity to avoid disk storms — it has no watchdog.
        if ($this->workerSlot !== null) {
            $this->writeWorkerRecord();
            return;
        }
        if ($this->activity() === $this->writtenActivity) {
            return;
        }
        $this->writeStatus();
    }

    /**
     * Persists the status record now: a daemon worker writes its per-slot
     * heartbeat, a bare foreground process writes (and owns) its own record.
     */
    private function writeStatus(): void
    {
        // A daemon worker reports to its per-slot heartbeat record in the owner
        // daemon's store; a bare foreground process owns its own record.
        if ($this->workerSlot !== null) {
            $this->writeWorkerRecord();
            return;
        }
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

    /**
     * Writes the per-slot heartbeat a daemon worker reports to the supervisor.
     * Keyed by the owner daemon's class + slot, so the supervisor aggregates all
     * workers into its {@see \Flytachi\Winter\Kernel\Process\Daemon\DaemonStatus}.
     */
    private function writeWorkerRecord(): void
    {
        $activity = $this->activity();
        $this->lastHeartbeatAt = microtime(true);
        try {
            $key = hash('xxh64', $this->ownerClass . '#' . $this->workerSlot);
            (new ProcessStore($this->ownerClass))->main()->write($key, new ProcessStatus(
                pid: $this->pid,
                className: static::class,
                state: $this->state,
                activity: $activity,
                startedAt: $this->startedAt,
                concurrency: $this->concurrency,
                heartbeatAt: time(),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Worker status write failed: ' . $e->getMessage());
            return;
        }
        $this->writtenActivity = $activity;
    }

    /**
     * Stable store key for this process class (its status record's address).
     */
    final protected static function key(): string
    {
        return hash('xxh64', static::class);
    }

    /**
     * The runnable store — one status record per class — shared by the CLI and
     * the web layer so both read the same source of truth.
     */
    final protected static function store(): FileStorage
    {
        return (new ProcessStore(static::class))->main();
    }
}
