<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Config;

use Psr\Log\LoggerInterface;

/**
 * Applies the per-worker memory ceiling and checks that the fleet can fit in the box.
 *
 * A Swoole worker serves many requests at once out of **one** PHP heap, so
 * `memory_limit` bounds their sum rather than any single request. When the sum is
 * reached PHP raises a fatal, the worker dies, and every request it was holding dies
 * with it — measured on a live application: 1 193 coroutines discarded in one go, and
 * with a single worker the whole container went down.
 *
 * The framework touches nothing unless asked ({@see ServerSettings::memoryLimit()}).
 * What it always does is **say the arithmetic out loud**, because the two numbers that
 * decide whether a box holds are configured in different places and nobody multiplies
 * them: a limit is per worker, and `worker_num` of them run at once, on top of opcache's
 * shared memory.
 *
 * The check warns rather than refuses. Unlike the DI scope check — where the condition
 * was certainly wrong — this is a worst-case estimate: every worker peaking together is
 * rare, and over-committing memory is a legitimate choice. Refusing would break working
 * deployments over an estimate.
 */
final class WorkerMemory
{
    /** Where cgroup v2 publishes the container's memory ceiling. */
    private const string CGROUP_V2 = '/sys/fs/cgroup/memory.max';

    /** cgroup v1 equivalent, still what older container runtimes expose. */
    private const string CGROUP_V1 = '/sys/fs/cgroup/memory/memory.limit_in_bytes';

    private function __construct()
    {
    }

    /**
     * Applies the configured limit to the current worker process.
     *
     * A no-op when nothing was configured, so an application that never asks keeps
     * whatever PHP was started with.
     */
    public static function apply(?string $limit): void
    {
        if ($limit === null || $limit === '') {
            return;
        }

        ini_set('memory_limit', $limit);
    }

    /**
     * Reports what the fleet will ask of the machine, and warns when it will not fit.
     *
     * Called once at server start, before the workers exist.
     *
     * @param string|null $limit Configured per-worker limit, or null to read the ini.
     * @param int $workers Number of worker processes that will run.
     * @param int|null $boxBytes The machine's ceiling; detected from cgroup when null.
     *   A test seam — the same shape as the pool's injectable clock — because a host
     *   with no cgroup limit cannot exercise the arithmetic this method exists for.
     */
    public static function check(
        ?string $limit,
        int $workers,
        LoggerInterface $logger,
        ?int $boxBytes = null,
    ): void {
        $effective = $limit ?? (string) ini_get('memory_limit');
        $perWorker = self::toBytes($effective);

        if ($perWorker < 0) {
            $logger->warning(
                'memory_limit is -1 (unlimited) — under Swoole this does not remove the '
                . 'ceiling, it moves it to the kernel. PHP will never stop the process, so '
                . 'a runaway request grows until the OOM killer sends SIGKILL: no shutdown '
                . 'functions, no log entry, and the whole container when the server is PID 1. '
                . 'A real limit at least fails through PHP, which the manager recovers from.',
            );
            return;
        }

        if ($perWorker === 0) {
            return; // unparseable value — PHP will complain about it far better than we can
        }

        $fleet   = $perWorker * max(1, $workers);
        $opcache = self::opcacheBytes();
        $needed  = $fleet + $opcache;
        $box     = $boxBytes ?? self::containerLimitBytes();

        if ($box === null || $needed <= $box) {
            return;
        }

        $logger->warning(sprintf(
            'Memory over-commit: %d worker(s) × %s = %s, plus %s of opcache, needs %s — '
            . 'the container is limited to %s. Every worker peaking at once would exceed it, '
            . 'and the kernel kills the process rather than PHP failing the request. '
            . 'Lower memory_limit, run fewer workers, or give the container more memory.',
            max(1, $workers),
            self::format($perWorker),
            self::format($fleet),
            self::format($opcache),
            self::format($needed),
            self::format($box),
        ));
    }

    /**
     * Hands the allocator's idle reserve back to the operating system, if it has grown
     * past `$threshold` bytes. Returns the number of bytes released.
     *
     * PHP frees memory to **its own allocator**, not to the kernel. Whether the kernel
     * ever sees it back depends on how it was taken: a single large block is mapped
     * directly and unmapped on release, but many small objects live in the allocator's
     * chunks, and those chunks are kept for reuse. Measured — 600 000 small objects:
     *
     * ```
     * in work            : used 282 MB, taken from the OS 284 MB   → reserve   1.7 MB
     * request finished   : used  10 MB, taken from the OS 268 MB   → reserve 258.0 MB
     * after this call    : used  10 MB, taken from the OS  12 MB   → reserve   2.0 MB
     * ```
     *
     * That middle line is the problem: the worker holds a quarter of a gigabyte it does
     * not use, for the rest of its life. On a host running several containers it is
     * first-come-first-served — one spike and the memory is spoken for.
     *
     * The trigger is the **reserve**, `memory_get_usage(true) - memory_get_usage()`, not
     * the peak. Two reasons. While a request is genuinely working the reserve stays small
     * (the memory is in use), so sustained load does not trip it — where the peak, which
     * never decreases, would trip on every request after the first heavy one. And it is
     * self-correcting: releasing closes the gap, so the next call finds nothing to do.
     *
     * Cost, measured: 80.7 ms when there is 128 MB to return, **5 µs** when there is
     * nothing — so the ordinary request pays microseconds — and about 10 % on the next
     * large allocation, which has to take fresh chunks. Worth it once after a spike;
     * ruinous on every request, which is what the threshold prevents.
     *
     * `gc_collect_cycles()` does none of this — measured, it collects nothing here and
     * frees nothing. Cycles are not what is being held.
     */
    public static function trimIfIdle(int $threshold): int
    {
        if ($threshold <= 0) {
            return 0;
        }
        if (self::idleReserve() < $threshold) {
            return 0;
        }

        return gc_mem_caches();
    }

    /**
     * Memory the allocator has taken from the kernel and is not using — what
     * {@see trimIfIdle()} decides on, and worth reading on its own when asking where a
     * worker's resident size went.
     *
     * Its virtue over `memory_get_peak_usage()` is that it **falls again**. A worker in
     * the middle of a large request has a high peak and almost no reserve, because the
     * memory is in use; once the request ends the reserve is what stayed behind. The peak
     * never decreases at all, so it says "something large happened here once" forever and
     * cannot tell whether anything is being held now.
     */
    public static function idleReserve(): int
    {
        return memory_get_usage(true) - memory_get_usage();
    }

    /** Parses a PHP memory value ('32M', '512K', '0') into bytes. */
    public static function bytes(string $value): int
    {
        return self::toBytes($value);
    }

    /** The container's memory ceiling in bytes, or null when it is unlimited/unknown. */
    private static function containerLimitBytes(): ?int
    {
        foreach ([self::CGROUP_V2, self::CGROUP_V1] as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $raw = trim((string) @file_get_contents($path));

            // cgroup v2 writes the literal "max" when unbounded; v1 writes a number so
            // large it means the same thing (typically PHP_INT_MAX rounded to page size).
            if ($raw === '' || $raw === 'max' || !ctype_digit($raw)) {
                return null;
            }
            $bytes = (int) $raw;

            return $bytes > 0 && $bytes < PHP_INT_MAX / 2 ? $bytes : null;
        }

        return null;
    }

    /** Opcache's shared memory, which is charged to the box once, not per worker. */
    private static function opcacheBytes(): int
    {
        if (!function_exists('opcache_get_status') || ini_get('opcache.enable') === false) {
            return 0;
        }
        $mb = (int) ini_get('opcache.memory_consumption');

        return $mb > 0 ? $mb * 1024 * 1024 : 0;
    }

    /**
     * PHP shorthand ('256M', '1G') to bytes. Returns -1 for unlimited, 0 when the value
     * makes no sense — PHP itself reports that better than a second opinion would.
     */
    private static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        if (!preg_match('/^(\d+)\s*([KMG])?$/i', $value, $m)) {
            return 0;
        }

        return (int) $m[1] * match (strtoupper($m[2] ?? '')) {
            'K'     => 1024,
            'M'     => 1024 ** 2,
            'G'     => 1024 ** 3,
            default => 1,
        };
    }

    private static function format(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return round($bytes / 1024 ** 3, 1) . ' GiB';
        }

        return round($bytes / 1024 ** 2) . ' MiB';
    }
}
