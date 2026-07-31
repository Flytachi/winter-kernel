<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\ConnectionPool;

use Closure;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * A HikariCP-inspired connection pool for Swoole coroutines.
 *
 * Unlike a plain `Swoole\ConnectionPool` (a dumb channel), this pool actively keeps
 * its connections usable so it survives a database outage the way FPM did for free
 * (a fresh connection per request):
 *
 *   - **idle-gated validation** — on borrow, a connection idle longer than
 *     {@see PoolPolicy::$aliveBypassWindow} is probed ({@see ConnectionFactory::validate()});
 *     a dead one is retired and a fresh one opened. Hot connections skip the probe →
 *     zero overhead on healthy traffic.
 *   - **maxLifetime rotation** — a connection older than {@see PoolPolicy::$maxLifetime}
 *     (jittered) is retired before it can go stale server-side.
 *   - **connectionTimeout** — a borrow waits at most {@see PoolPolicy::$connectionTimeout}
 *     for a free slot, then fails fast with {@see PoolException}.
 *
 * The pool is driver-agnostic: it drives a {@see ConnectionFactory} (create / validate
 * / close), so the same pool serves DB (CDO) and Redis alike.
 *
 * Must run inside a Swoole coroutine (it uses a coroutine Channel).
 */
final class ConnectionPool
{
    /** Retire-and-retry attempts in borrow() before giving up (dead connection storm). */
    private const int MAX_RETIRE_LOOPS = 3;

    /** Idle connections waiting to be borrowed. */
    private ?Channel $idle = null;

    /** Connections opened (idle in the channel + borrowed out). */
    private int $total = 0;

    /** @var Closure(): float Monotonic seconds source (test seam). */
    private readonly Closure $clock;

    /**
     * @param ConnectionFactory $factory Opens / probes / closes the pooled resource.
     * @param PoolPolicy $policy Sizing and lifecycle tuning.
     * @param (Closure(): float)|null $clock Monotonic clock override for tests;
     *   defaults to `hrtime(true) / 1e9`.
     */
    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly PoolPolicy $policy = new PoolPolicy(),
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
        $this->idle  = new Channel($this->policy->maximumPoolSize);
    }

    /**
     * Borrows a live connection: reuse an idle one (idle-gated probe), grow up to
     * maximumPoolSize, or wait connectionTimeout for a release. Expired or dead
     * connections are retired and a fresh one is obtained.
     *
     * @throws PoolException On exhaustion, connect failure, or repeated dead borrows.
     */
    public function borrow(): PoolEntry
    {
        for ($attempt = 0; $attempt <= self::MAX_RETIRE_LOOPS; ++$attempt) {
            $entry = $this->acquire();
            if ($entry === null) {
                throw PoolException::exhausted($this->policy->connectionTimeout);
            }
            if ($this->isExpired($entry) || ($this->needsProbe($entry) && !$this->probe($entry))) {
                $this->discard($entry);
                continue;
            }
            $entry->lastUsedAt = $this->now();
            return $entry;
        }

        throw PoolException::unusable(self::MAX_RETIRE_LOOPS + 1);
    }

    /** Returns a borrowed connection to the pool for reuse. */
    public function release(PoolEntry $entry): void
    {
        if ($this->idle === null) {
            $this->discard($entry);
            return;
        }
        $entry->lastUsedAt = $this->now();
        $this->idle->push($entry);
    }

    /**
     * Retires a connection (close + free the slot) instead of returning it — for a
     * connection-level failure (SQLSTATE 08xxx etc.) detected during use, so the dead
     * connection is never handed out again.
     */
    public function evict(PoolEntry $entry): void
    {
        $this->discard($entry);
    }

    /** @return array{total: int, idle: int, active: int, maximum: int} */
    public function stats(): array
    {
        $idle = $this->idle?->length() ?? 0;
        return [
            'total'   => $this->total,
            'idle'    => $idle,
            'active'  => $this->total - $idle,
            'maximum' => $this->policy->maximumPoolSize,
        ];
    }

    /** Closes every idle connection and the pool itself. */
    public function close(): void
    {
        if ($this->idle === null) {
            return;
        }
        while ($this->idle->length() > 0) {
            $entry = $this->idle->pop(0.001);
            if ($entry instanceof PoolEntry) {
                $this->safeClose($entry->resource);
            }
        }
        $this->idle->close();
        $this->idle  = null;
        $this->total = 0;
    }

    // ── internals ──────────────────────────────────────────────────────────────

    /** Gets an idle connection, grows the pool, or waits for a release. */
    private function acquire(): ?PoolEntry
    {
        if ($this->idle !== null && $this->idle->length() > 0) {
            $entry = $this->idle->pop(0.001);
            if ($entry instanceof PoolEntry) {
                return $entry;
            }
        }
        if ($this->total < $this->policy->maximumPoolSize) {
            return $this->make();
        }
        $entry = $this->idle?->pop($this->policy->connectionTimeout);
        return $entry instanceof PoolEntry ? $entry : null;
    }

    private function make(): PoolEntry
    {
        // Reserve the slot before the (possibly yielding) connect so concurrent
        // borrows don't over-provision past maximumPoolSize.
        ++$this->total;
        try {
            $resource = $this->factory->create();
        } catch (Throwable $e) {
            --$this->total;
            throw PoolException::connectFailed($e);
        }
        $now = $this->now();
        return new PoolEntry($resource, $now, $now, $this->computeExpiry($now));
    }

    private function discard(PoolEntry $entry): void
    {
        $this->safeClose($entry->resource);
        if ($this->total > 0) {
            --$this->total;
        }
    }

    private function isExpired(PoolEntry $entry): bool
    {
        return $entry->expiresAt !== null && $this->now() >= $entry->expiresAt;
    }

    private function needsProbe(PoolEntry $entry): bool
    {
        return ($this->now() - $entry->lastUsedAt) > $this->policy->aliveBypassWindow;
    }

    private function probe(PoolEntry $entry): bool
    {
        try {
            return $this->factory->validate($entry->resource);
        } catch (Throwable) {
            return false;
        }
    }

    private function computeExpiry(float $now): ?float
    {
        if ($this->policy->maxLifetime <= 0.0) {
            return null;
        }
        $life = $this->policy->maxLifetime;
        $jit  = $this->policy->maxLifetimeJitter;
        if ($jit > 0.0) {
            $spread = $life * $jit;
            $life  += (mt_rand() / mt_getrandmax()) * 2 * $spread - $spread;
        }
        return $now + $life;
    }

    private function safeClose(object $resource): void
    {
        try {
            $this->factory->close($resource);
        } catch (Throwable) {
        }
    }

    private function now(): float
    {
        return ($this->clock)();
    }
}
