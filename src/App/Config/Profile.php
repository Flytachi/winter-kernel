<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Config;

/**
 * The stance a server takes on its own memory — and, through it, every limit that keeps
 * a worker alive.
 *
 * A worker serves many requests out of **one** heap, so the question that decides
 * everything is how much of that heap one request may use. Ask for too much and few
 * requests fit; ask for too little and a request that overruns kills the worker and every
 * other request it was holding. A profile answers that one question, and the rest —
 * concurrency, connections, when memory is handed back, how often the worker is replaced
 * — follows arithmetically from it.
 *
 * ```
 * $server->profile(Profile::Performance);
 * ```
 *
 * **The axis is the shape of a request, not caution.** `Performance` is not "faster at
 * the cost of safety" — it is the profile for services whose requests are *small*, and it
 * gives such a service more concurrency than `Stable` would, not less. Picking one is
 * answering "how much memory does one of my requests use?", which is measurable:
 *
 * ```
 * $before = memory_get_usage();
 * // ... the handler ...
 * $after  = memory_get_usage();   // under 64 KB → Performance; over 200 KB → Stable
 * ```
 *
 * Everything a profile decides is a **default**, resolved when the value is read. An
 * explicit `maxConcurrency()`, `maxRequest()` or `SERVER_*` variable always wins, whatever
 * order it was set in.
 */
enum Profile: string
{
    /** Heavy requests — reports, exports, a monolith with wide joins. 512 KB each. */
    case Stable = 'stable';

    /** Ordinary CRUD. 128 KB per request, and the default when nothing is said. */
    case Balance = 'balance';

    /** Small requests — a thin API, a proxy, an integration bridge. 64 KB each. */
    case Performance = 'performance';

    /**
     * Guards off — for benchmarks, not for production.
     *
     * Not "Performance, more so": throughput plateaus long before the memory ceiling
     * (measured on a live application, 2 250 req/s at 500 concurrent, with more
     * concurrency adding nothing), so removing the caps does not raise it. What it removes
     * is **periodic interference that distorts a measurement**: handing memory back pauses
     * for tens of milliseconds and shows up in p99, replacing a worker empties its
     * connection pool mid-run, and the request watchdog runs a timer of its own.
     *
     * A run under this profile can exhaust the worker's memory, and over a long one
     * Swoole's per-request leak accumulates with no replacement to clear it. Both are
     * acceptable for a bounded benchmark and for nothing else.
     */
    case Stress = 'stress';

    /**
     * Heap an in-flight request holds before it has allocated anything of its own —
     * measured, not assumed: 600 requests suspended in a trivial handler cost 78.2 KB
     * each, against 68.0 KB for the same connections idle. The difference is the
     * coroutine and the request/response pair; the rest is the connection underneath.
     */
    private const int REQUEST_FLOOR = 78 * 1024;

    /**
     * Heap one open connection holds while its client is connected but asking nothing —
     * measured linearly at 401, 801 and 1 201 connections, and released in full when they
     * close. It is charged to PHP's own limit, not merely to RSS, which is why a worker
     * with the default 128M dies at roughly 1 900 idle keep-alive connections.
     */
    private const int CONNECTION_FLOOR = 68 * 1024;

    /**
     * Bytes leaked per request through the whole pipeline. Swoole loses 56 bytes on every
     * coroutine suspend/resume — measured to the byte over five runs of ten thousand, and
     * surviving both `gc_collect_cycles()` and `gc_mem_caches()` — and a request suspends
     * several times. {@see maxRequest()} turns this into a replacement interval.
     */
    private const int LEAK_PER_REQUEST = 170;

    /** Assumed heap when the limit cannot be read (`-1`, or a value PHP cannot parse). */
    private const int ASSUMED_LIMIT = 128 * 1024 * 1024;

    /** True while the profile imposes limits at all — false only for {@see Stress}. */
    public function guards(): bool
    {
        return $this !== self::Stress;
    }

    /**
     * Heap one in-flight request may use for **its own work** — the entities it loads,
     * the string it serialises — on top of {@see REQUEST_FLOOR}, which is the framework's
     * and which no application can influence. Zero for {@see Stress}, which budgets
     * nothing because it caps nothing.
     *
     * It is an assumption about the application, however the profile is described: a
     * ceiling of 1 170 in flight is the same statement as "a request here fits in 64 KB".
     * Choose the profile knowing that, and an application whose requests are heavier is
     * not made safe by the framework — it is made to die later.
     */
    public function requestBudget(): int
    {
        return match ($this) {
            self::Stable      => 512 * 1024,
            self::Balance     => 128 * 1024,
            self::Performance => 64 * 1024,
            self::Stress      => 0,
        };
    }

    /**
     * Requests this profile allows in flight at once, given the heap left after the
     * worker's own baseline. `0` means no cap.
     *
     * Each is budgeted at floor + budget, plus a second connection at
     * {@see CONNECTION_FLOOR} for the client that is connected and *not* currently asking
     * — the ordinary keep-alive case. That one-to-one assumption is what
     * {@see connections()} spends; a service whose clients hold connections open far
     * longer says so with `maxConnections()`.
     *
     * How far this can be pushed is bounded by the floor, not by the profile: measured at
     * 256M, cutting the budget to **zero** — an application allowed to allocate nothing at
     * all — raises the ceiling from 1 170 to 1 683, and no further. At `Performance` the
     * framework's own 146 KB is already 70 % of what a request costs. What multiplies the
     * ceiling is memory: the same profile gives 2 418 at 512M and 4 915 at 1G.
     */
    public function concurrency(int $availableBytes): int
    {
        if (!$this->guards()) {
            return 0;
        }

        $perRequest = self::REQUEST_FLOOR + $this->requestBudget() + self::CONNECTION_FLOOR;

        return max(1, intdiv(max(0, $availableBytes), $perRequest));
    }

    /**
     * Connections allowed at once: the working ones and an idle one apiece, but never
     * more than the process has file descriptors for. `0` = no cap.
     *
     * A socket is a descriptor, so `ulimit -n` is a second ceiling entirely independent of
     * memory — and the tighter one on a stingy host. Swoole enforces it either way: asked
     * for more it prints `max_connection is exceed the maximum value, it's reset to N` and
     * silently uses N. Clamping here means the number the framework reports is the number
     * that will apply, and the warning never appears.
     *
     * Only the derived value is clamped. An explicit `maxConnections()` above the limit is
     * left to Swoole, so its warning still reaches the operator who asked for it — that
     * warning is the one thing telling them to raise `ulimit -n`.
     */
    public function connections(int $availableBytes, int $descriptorLimit): int
    {
        if (!$this->guards()) {
            return 0;
        }

        return min($this->concurrency($availableBytes) * 2, max(1, $descriptorLimit));
    }

    /**
     * Idle heap a worker may hold before handing it back to the operating system.
     * `0` never hands anything back.
     *
     * Scaled to the limit rather than fixed, because what counts as "suspiciously
     * unused" depends on how much there is: an ordinary worker carries two or three
     * megabytes of reserve whatever its ceiling. Handing memory back costs about 80 ms
     * when there is a lot of it, so the profile that avoids pauses waits longer.
     */
    public function trimThreshold(int $limitBytes): int
    {
        $limit = $limitBytes > 0 ? $limitBytes : self::ASSUMED_LIMIT;

        return match ($this) {
            self::Stable      => intdiv($limit, 16),
            self::Balance     => intdiv($limit, 8),
            self::Performance => intdiv($limit, 4),
            self::Stress      => 0,
        };
    }

    /**
     * Requests a worker serves before it is replaced. `0` never replaces it.
     *
     * Derived from what the leak is allowed to reach: a bigger heap tolerates more of it
     * before replacement is worth its cost — the new worker starts with an empty
     * connection pool and has to fill it, about 30 ms.
     */
    public function maxRequest(int $limitBytes): int
    {
        $limit = $limitBytes > 0 ? $limitBytes : self::ASSUMED_LIMIT;
        $share = match ($this) {
            self::Stable      => 0.05,
            self::Balance     => 0.10,
            self::Performance => 0.20,
            self::Stress      => 0.0,
        };

        return (int) ($limit * $share / self::LEAK_PER_REQUEST);
    }

    /**
     * How far apart workers may drift before recycling — a tenth of {@see maxRequest()}.
     *
     * Swoole **adds** a random amount up to this to the limit, so the real one is
     * `max_request + rand(0, grace)`: verified, at `max_request = 20, grace = 15` workers
     * served 30, 27, 34 and 26 requests, against exactly 20 apiece at `grace = 0`.
     * Without it, workers counting to the same number under even traffic recycle almost
     * together, and several connection pools go cold at once.
     */
    public function maxRequestGrace(int $limitBytes): int
    {
        return intdiv($this->maxRequest($limitBytes), 10);
    }
}
