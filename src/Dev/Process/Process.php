<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\K2\Concurrent\Future;
use Flytachi\Winter\K2\Dev\Process\Engine\Engines;
use Flytachi\Winter\K2\Dev\Process\Engine\ProcessEngine;
use Flytachi\Winter\K2\Process\Entity\TStats;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\Thread\Thread;
use Psr\Log\LoggerInterface;

/**
 * Base for a managed, runtime-agnostic process.
 *
 * A process is a body the developer writes once in {@see run()}. The framework
 * supplies the lifecycle (start / stop / status, visible from CLI and web), the
 * runtime (coroutines under Swoole, forks otherwise), a shared PPA pool, and
 * graceful shutdown — none of which the body has to know about.
 *
 * The body may be one-shot (walk the database, build an archive, return) or
 * long-lived (loop on a connection until stopped). Concurrency is opt-in: call
 * {@see spawn()} only where it helps.
 *
 * ```
 * class BillingConsumer extends Process
 * {
 *     protected int $concurrency = 20;
 *
 *     public function run(): void
 *     {
 *         $ch = $this->rabbit->connect();
 *         while ($this->running()) {
 *             $msg = $ch->get();
 *             if ($msg === null) { $this->sleep(0.2); continue; }
 *             $this->spawn(fn() => $this->handle($msg));
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

    protected LoggerInterface $logger;
    protected int $pid;
    private ProcessEngine $engine;

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
     * Dispatches a task concurrently (coroutine under Swoole, fork otherwise),
     * capped by {@see $concurrency}.
     */
    final protected function spawn(callable $task): Future
    {
        return $this->engine->spawn($task);
    }

    /**
     * Pauses the body — non-blocking under Swoole.
     */
    final protected function sleep(float $seconds): void
    {
        $this->engine->sleep($seconds);
    }

    /**
     * False once a stop signal has arrived; drive loops with it.
     */
    final protected function running(): bool
    {
        return $this->engine->running();
    }

    /**
     * Requests a graceful stop from inside the body or a signal hook — flips
     * {@see running()} to false so the loop exits on its next check.
     */
    final protected function requestStop(): void
    {
        $this->engine->requestStop();
    }

    // -------------------------------------------------------------------------
    // Signal hooks — override to react to a specific signal
    // -------------------------------------------------------------------------

    /**
     * SIGTERM — the standard "please stop" signal (what {@see stop()} sends).
     * Default: graceful stop. Override to add cleanup before the loop exits.
     */
    protected function onTerminate(): void
    {
        $this->requestStop();
    }

    /**
     * SIGINT — interrupt (Ctrl-C). Default: graceful stop.
     */
    protected function onInterrupt(): void
    {
        $this->requestStop();
    }

    /**
     * SIGHUP — the connection/terminal closed, conventionally "reload".
     * Default: graceful stop. Override to reload config without stopping
     * (simply do not call {@see requestStop()}).
     */
    protected function onClose(): void
    {
        $this->requestStop();
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Runs the process in the foreground, registering it in the store so
     * {@see status()} and {@see stop()} can reach it from another terminal.
     */
    public static function start(): void
    {
        /** @var static $self */
        $self = Container::getInstance()->make(static::class);
        $self->boot();
    }

    /**
     * Launches the process detached in the background and returns its PID. The
     * child registers itself in the store, so {@see status()} / {@see stop()}
     * reach it exactly as with a foreground start.
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
     * @param bool $stats Attach live resource stats (CPU/memory via `ps`).
     */
    final public static function status(bool $stats = false): ?ProcessStatus
    {
        try {
            $store = static::store();
            $key = static::key();
            /** @var ?ProcessStatus $status */
            $status = $store->read($key);
            if (!$status) {
                return null;
            }
            if (!posix_getpgid($status->pid)) {
                $store->del($key);
                return null;
            }
            if ($stats) {
                $status->stats = TStats::ofPid($status->pid);
            }
            return $status;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Sends a graceful stop signal. Returns false when nothing is running.
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
        $store = static::store();
        $key = static::key();
        $store->write($key, new ProcessStatus(
            pid: getmypid(),
            className: static::class,
            state: ProcessState::RUNNING,
            startedAt: time(),
            concurrency: $this->concurrency,
        ));

        try {
            $this->runWorker();
        } catch (\Throwable $e) {
            $this->logger->critical(
                $e->getMessage()
                . (env('DEBUG', false) ? "\n" . $e->getTraceAsString() : '')
            );
        } finally {
            $store->del($key);
        }
    }

    /**
     * Sets up the runtime and runs the body. No store bookkeeping — this is the
     * unit a {@see Daemon} supervisor forks per worker. A throwable propagates so
     * the supervisor can observe a failed exit.
     */
    protected function runWorker(): void
    {
        $this->pid = getmypid();
        $this->logger = LoggerFactory::getLogger(static::class);
        $this->engine = Engines::common($this->concurrency);

        $this->engine->enter(fn() => $this->run(), [
            SIGTERM => fn() => $this->onTerminate(),
            SIGINT => fn() => $this->onInterrupt(),
            SIGHUP => fn() => $this->onClose(),
        ]);
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
