<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

/**
 * What the current OS process was started as — read through
 * {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process::current()}.
 *
 * A description rather than the process object, and that is the point: it is known
 * before the process is built. A process's `#[Autowired]` dependencies are constructed
 * with it, and a repository's constructor sets its DB config up — so a config that sizes
 * its pool by where it runs asks this question before any process instance exists.
 *
 * ```
 * public function setUp(): void
 * {
 *     $this->poolMaxConnections = Process::current() === null ? 10 : 3;
 * }
 * ```
 *
 * A daemon's supervisor is not a running process — it has no body — so it reads `null`
 * here, like a web worker; {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon::supervising()}
 * tells it apart.
 */
final readonly class RunningProcess
{
    /**
     * @param class-string<\Flytachi\Winter\Kernel\Process\Stereotype\Process> $class The
     *   process class — for a daemon worker, the daemon, also when it runs an external
     *   `$workerClass`.
     * @param int|null $slot The daemon worker's slot; null for a plain process.
     */
    public function __construct(
        public string $class,
        public ?int $slot = null,
    ) {
    }

    /** Whether this is a worker of a daemon rather than a plain process. */
    public function isDaemonWorker(): bool
    {
        return $this->slot !== null;
    }
}
