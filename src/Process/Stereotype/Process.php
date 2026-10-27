<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Stereotype;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRuntime;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRecord;
use Flytachi\Winter\Kernel\App\Supervision\ComponentChannel;
use Flytachi\Winter\Kernel\App\Supervision\ComponentControl;
use Flytachi\Winter\Kernel\Concurrent\Future;
use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;
use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Process\Engine\Engines;
use Flytachi\Winter\Kernel\Process\Engine\ProcessEngine;
use Flytachi\Winter\Kernel\Process\ForkReset;
use Flytachi\Winter\Kernel\Process\Internal\SingletonLock;
use Flytachi\Winter\Kernel\Process\ProcessAlreadyRunningException;
use Flytachi\Winter\Kernel\Process\ProcessManagedException;
use Flytachi\Winter\Kernel\Process\ProcessRunnable;
use Flytachi\Winter\Kernel\Process\ProcessState;
use Flytachi\Winter\Kernel\Process\ProcessStatus;
use Flytachi\Winter\Kernel\Process\ProcessStore;
use Flytachi\Winter\Kernel\Process\ResourceUsage;
use Flytachi\Winter\Kernel\Process\RunningProcess;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Ppa\Pool\PoolTelemetry;
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

    /** What this OS process was started as; see {@see current()}. */
    private static ?RunningProcess $current = null;

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
     * What this OS process was started as — a process, or a daemon worker with its slot
     * — or null where no process is running: a web worker, the console, a daemon's
     * supervisor (see {@see Daemon::supervising()}).
     *
     * Set before the process is built, so it already answers while `#[Autowired]`
     * dependencies are being constructed — the moment a DB config runs `setUp()` and
     * picks its pool size. Tasks dispatched with {@see spawn()} run as the same process.
     */
    public static function current(): ?RunningProcess
    {
        return self::$current;
    }

    /**
     * Records what this OS process runs as.
     *
     * @internal Called by the runtime before the process is built.
     */
    final protected static function runAs(?RunningProcess $running): void
    {
        self::$current = $running;
    }

    /**
     * Runs the process in the foreground, registering it in the store so
     * {@see status()} / {@see stop()} reach it from another terminal.
     */
    public static function start(): void
    {
        static::ensureNotRunning();

        // Before make(): building the process builds its dependencies, and a repository
        // among them sets up its DB config — which may ask what it runs in.
        self::runAs(new RunningProcess(static::class));

        /** @var static $self */
        $self = Container::getInstance()->make(static::class);
        $self->boot();
    }

    /**
     * Launches the process detached in the background and returns its PID.
     *
     * @param string|null $output '/dev/null' (default) or a file path for the child's stdio.
     */
    public static function dispatch(?string $output = '/dev/null'): int
    {
        return static::dispatchWith([], $output);
    }

    /**
     * Starts the process in a detached child that was launched with launch arguments —
     * the child side of {@see dispatchWith()}. A plain process takes none; a stereotype
     * that needs some (a {@see \Flytachi\Winter\Kernel\WebSocket\WebSocket} port)
     * overrides this to read them.
     *
     * @param array<string, string|bool> $args What {@see dispatchWith()} was given.
     * @internal Called by {@see ProcessRunnable} in the detached child.
     */
    public static function startWith(array $args): void
    {
        static::start();
    }

    /**
     * Launches the process detached, handing launch arguments to the child's
     * {@see startWith()}. They cross a process boundary as command-line options, so
     * values arrive as strings.
     *
     * @param array<string, string> $args
     * @param string|null $output '/dev/null' or a file path for the child's stdio.
     */
    final protected static function dispatchWith(array $args, ?string $output): int
    {
        // While the application runs here, it starts every process: a component it was
        // declared with, with its settings; any other, taken in for one run. It is asked
        // to, and the call waits a moment for the new process.
        if (!ApplicationRuntime::launching(static::class)) {
            $app = ApplicationRuntime::serving();
            if ($app !== null) {
                return static::resumeIn($app, $args);
            }
        }
        static::ensureNotRunning();

        return new Thread(
            new ProcessRunnable(static::class),
            'process',
            new \ReflectionClass(static::class)->getShortName(),
        )->start(arguments: $args, outputTarget: $output, detached: true);
    }

    /**
     * Refuses to launch a second instance of the same class.
     *
     * @throws ProcessAlreadyRunningException If one is already running.
     */
    protected static function ensureNotRunning(): void
    {
        if (!ApplicationRuntime::launching(static::class)) {
            // A component of the running application is started there, not here.
            $app = ApplicationRuntime::managing(static::class);
            if ($app !== null) {
                throw new ProcessManagedException(
                    static::class . " is a component of the running application [PID {$app->pid}]: it is started "
                    . 'there — with ' . static::class . '::dispatch() or `call process … start`.'
                );
            }
            // Started here, it would run beside the application, outside it — a second
            // scheduler firing every task twice.
            $elsewhere = ApplicationRuntime::servingElsewhere();
            if ($elsewhere !== null) {
                throw new ProcessManagedException(
                    "The application runs in another environment ({$elsewhere->host}) [PID {$elsewhere->pid}]: "
                    . 'start ' . static::class . ' there (for a container: docker exec … php call …).'
                );
            }
        }
        $info = static::status();
        if ($info !== null && self::isForeign($info)) {
            throw new ProcessManagedException(
                static::class . " runs in another environment ({$info->host}) [PID {$info->pid}]: "
                . 'manage it there (for a container: docker exec … php call …).'
            );
        }
        // A record with the caller's own PID is a dead instance's, not a live one: the
        // caller is the one starting. Only reachable when the lock cannot be read (the
        // PID fallback in status()) — a container restarted after a SIGKILL hands the
        // same PID to the new process. Were this process the running instance after
        // all, boot() would still refuse on the lock.
        if ($info !== null && $info->pid !== getmypid() && !self::isDown($info)) {
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
            // Written in another environment (a container's record read through a mounted
            // storage): its PID belongs to another process namespace, so nothing here can
            // confirm or deny it. Returned as it is; nobody may signal by it.
            if (self::isForeign($status)) {
                return $status;
            }
            // Written by the application's supervisor for a component that is down: it
            // holds as long as that run of the application does.
            if (self::isDown($status) && $status->managedBy !== null) {
                return $status->managedBy->isAlive() ? $status : null;
            }
            // Liveness by the lock, not by the PID in the record. The record is written
            // after the lock is taken and removed before it is released, so a record
            // with a free lock was left by a crash (SIGKILL skips the cleanup) — and its
            // PID may well be alive: a restarted container hands out the same PIDs, often
            // to the very process now starting, and stop() would signal a stranger.
            //
            // The lock is asked only when the record says it can be trusted — `flock`
            // does not exclude on every filesystem. Otherwise, and when the lock file
            // cannot be read, fall back to the PID: getpgid, not
            // kill(pid, 0), since getpgid needs no permission and a check from another
            // user (web as `winter`, process as root) must not see a live process dead.
            //
            // status() is a pure read — it never deletes: a read must have no side
            // effect, or any caller who can query could evict a running process's
            // record. A stale record is harmless (this returns null) and is overwritten
            // by the next start().
            $held = $status->locked ? self::probeLock() : null;
            if ($held === false || ($held === null && posix_getpgid($status->pid) === false)) {
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
        // A component of the application that is down has no process of its own: its
        // record names the supervisor, which must not be signalled. A record from another
        // environment names a PID that means nothing here.
        if (!$status || self::isDown($status) || self::isForeign($status)) {
            return false;
        }
        return posix_kill($status->pid, SIGTERM);
    }

    /**
     * Stops and starts the process again. (Not `restart()`: a daemon's `restart()` is its
     * workers' restart policy.)
     *
     * While the application runs, it restarts the process — at once, not counted as a
     * crash; the call only asks for it and returns. One that runs on its own beside the
     * application is stopped first, and the application starts it again. Without the
     * application, the process is stopped, waited for (up to a minute), and dispatched
     * again.
     *
     * @return bool False when it could not be restarted from here: it runs in another
     *              environment, or did not stop within the minute.
     * @link https://winterframe.net/docs/components Controlling a running component
     */
    final public static function relaunch(): bool
    {
        if (!ApplicationRuntime::launching(static::class)) {
            $app = ApplicationRuntime::serving();
            if ($app !== null) {
                $status = static::status();
                $outside = $status !== null && !self::isDown($status) && !$status->managedBy?->isRun($app);
                if ($outside) {
                    if (!static::stopAndWait()) {
                        return false;
                    }
                    ComponentControl::request($app->managedBy(), static::class, ComponentControl::START);
                    return true;
                }
                ComponentControl::request($app->managedBy(), static::class, ComponentControl::RESTART);
                return true;
            }
            if (ApplicationRuntime::servingElsewhere() !== null) {
                return false;
            }
        }
        if (!static::stopAndWait()) {
            return false;
        }
        static::dispatch();
        return true;
    }

    /**
     * Stops the running instance, if there is one, and waits up to a minute for it to go.
     *
     * @return bool False when it runs in another environment or did not stop in time.
     */
    private static function stopAndWait(): bool
    {
        $status = static::status();
        if ($status === null) {
            return true;
        }
        if (self::isForeign($status) || !static::stop()) {
            return false;
        }
        $deadline = microtime(true) + 60.0;
        while (($status = static::status()) !== null && !self::isDown($status)) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(100_000);
        }
        return true;
    }

    /**
     * Asks the running application to start this process — its component, or taken in
     * for one run — and waits up to a few seconds for it.
     *
     * @param array<string, string> $args What the process is started with.
     * @return int The new process's PID, or 0 when it is not up yet.
     * @throws ProcessAlreadyRunningException It is running already.
     */
    private static function resumeIn(ApplicationRecord $app, array $args): int
    {
        $status = static::status();
        if ($status !== null && !self::isDown($status)) {
            throw new ProcessAlreadyRunningException(
                static::class . " is already running [PID {$status->pid}] (since {$status->getStartedAt()})."
            );
        }
        ComponentControl::request($app->managedBy(), static::class, ComponentControl::START, $args);
        $deadline = microtime(true) + 5.0;
        do {
            usleep(50_000);
            $status = static::status();
            if ($status !== null && !self::isDown($status)) {
                return $status->pid;
            }
        } while (microtime(true) < $deadline);
        return 0;
    }

    /**
     * Whether a record is the supervisor's note of a component that is down — finished,
     * stopped on request, or failed — rather than a process of its own.
     */
    private static function isDown(ProcessStatus $status): bool
    {
        return $status->state === ProcessState::TERMINATED || $status->state === ProcessState::FAILED;
    }

    /** Whether a record was written in another environment, where its PID means nothing. */
    private static function isForeign(ProcessStatus $status): bool
    {
        return $status->host !== null && $status->host !== ApplicationRuntime::host();
    }

    /**
     * Records this class as a component of the application that is down on request.
     * Written by the supervisor; it lasts as long as the application run does.
     *
     * @internal Called by the application's supervisor.
     */
    final public static function recordStopped(): void
    {
        static::recordDown(ProcessState::TERMINATED);
    }

    /**
     * Removes what the supervisor recorded of this class — only that: a record of a
     * process of its own, or of another run, is left alone.
     *
     * @internal Called by the application's supervisor.
     */
    final public static function forgetRecord(): void
    {
        $run = ApplicationRuntime::current()?->managedBy();
        try {
            $status = static::store()->read(static::key());
        } catch (\Throwable) {
            return;
        }
        if ($run !== null && $status instanceof ProcessStatus && self::isDown($status) && $status->managedBy == $run) {
            static::store()->del(static::key());
        }
    }

    /**
     * The PID of a live instance of this class that the running application did not
     * start — one started on its own, or left from a previous run — or null.
     *
     * @internal Called by the application's supervisor.
     */
    final public static function outsider(): ?int
    {
        $status = static::status();
        if ($status === null || self::isDown($status)) {
            return null;
        }
        $run = ApplicationRuntime::current();
        if ($run !== null && $status->managedBy?->isRun($run)) {
            return null;
        }
        return $status->pid;
    }

    /**
     * Records this class as a component the application could not keep running — what
     * `status` shows for a non-critical component left down. Written by the supervisor,
     * so the record names the supervisor's process, alive as long as the application is.
     *
     * @internal Called by the application's supervisor.
     */
    final public static function recordFailed(): void
    {
        static::recordDown(ProcessState::FAILED);
    }

    private static function recordDown(ProcessState $state): void
    {
        $status = new ProcessStatus(
            pid: getmypid(),
            className: static::class,
            state: $state,
            activity: Activity::IDLE,
            startedAt: time(),
        );
        $status->host = ApplicationRuntime::host();
        $status->managedBy = ApplicationRuntime::current()?->managedBy();
        static::store()->write(static::key(), $status);
    }

    /**
     * Whether the component announces readiness itself, at a later point than the start
     * of {@see run()} — a WebSocket once it listens.
     *
     * @internal For the framework's stereotypes.
     */
    protected function readyOnRun(): bool
    {
        return true;
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
            // Under a supervisor: an end on request is not a failure, and not restarted.
            if ($this->stopping) {
                ComponentChannel::stopped();
            }
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
        // The daemon's channel to the application's supervisor is not this worker's: its
        // readiness and its stop are the daemon's to report.
        ComponentChannel::detach();
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
        //
        // Guarded by pid: without Swoole, `spawn()` runs each task in a forked child
        // that inherits this callback and exits when the task is done — which would run
        // the process's onShutdown() once per dispatched task, in the wrong process.
        $ownPid = $this->pid;
        register_shutdown_function(function () use ($ownPid): void {
            if (getmypid() !== $ownPid) {
                return;
            }
            $this->invokeShutdown();
        });

        $this->engine = Engines::common($this->concurrency, $this->grace);

        // A process keeps a connection pool of its own, apart from the web workers, so it
        // publishes as a source of its own for `call db pool`. Only an identity is set
        // here — nothing is armed until a pool is actually opened, and the timer is
        // stopped through RuntimeShutdown when the body is done.
        if (DepSupport::has(Dep::Ppa)) {
            $this->enablePoolTelemetry();
        }

        // A daemon worker writes its initial heartbeat at once, so the supervisor
        // sees it promote from STARTING to RUNNING without waiting a full tick.
        if ($this->workerSlot !== null) {
            $this->writeStatus();
        }
    }

    /**
     * Names this process as a pool-telemetry source: the class for a process, the
     * daemon and its slot for a daemon worker (slots are what the supervisor restarts,
     * so a restarted worker takes over its predecessor's record). The full class name:
     * two `Worker` classes in different namespaces are two sources, not one record both
     * keep overwriting.
     */
    private function enablePoolTelemetry(): void
    {
        // Sources other than web workers came with winter-ppa 1.2; an older ppa only
        // publishes for web workers, so a process has nothing to enable.
        if (!defined(PoolTelemetry::class . '::KIND_PROCESS')) {
            return;
        }

        $running = self::current() ?? new RunningProcess($this->ownerClass ?? static::class, $this->workerSlot);
        $name = $running->class;

        if (!$running->isDaemonWorker()) {
            PoolTelemetry::enable($name, PoolTelemetry::KIND_PROCESS);
            return;
        }
        PoolTelemetry::enable($name . '.' . $running->slot, PoolTelemetry::KIND_DAEMON);
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
                function (): void {
                    if ($this->readyOnRun()) {
                        ComponentChannel::ready();
                    }
                    $this->run();
                },
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
        $status = new ProcessStatus(
            pid: $this->pid,
            className: static::class,
            state: $this->state,
            activity: $activity,
            startedAt: $this->startedAt,
            concurrency: $this->concurrency,
        );
        $status->locked = $this->lockReliable;
        $status->host = ApplicationRuntime::host();
        $status->managedBy = ApplicationRuntime::managedBy();
        try {
            static::store()->write(static::key(), $status);
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
