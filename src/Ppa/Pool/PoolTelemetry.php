<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Pool;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Kernel\Kernel;

/**
 * Publishes each worker's pool utilisation to the shared runnable store so the CLI
 * can read it — the same pattern {@see \Flytachi\Winter\Kernel\Process\Stereotype\Process} uses for
 * `call process status`.
 *
 * A connection pool lives in **one worker's memory**. The CLI is a separate process,
 * so it can never inspect a running server's pool directly, and `/actuator/health`
 * only ever reports the single worker that served the request. Each worker therefore
 * writes a small record on a timer; `call db pool` reads every record and aggregates
 * them into a fleet-wide picture — and it works whether or not the actuator is enabled.
 *
 * ## Cost
 * The write happens on a timer coroutine, **never in the request path**, so it adds
 * nothing to request latency. The payload is a handful of integers per config
 * (a few hundred bytes) written once per {@see interval()} per worker. Records carry
 * a TTL of three intervals, so a worker that dies (or is killed) simply stops
 * refreshing and its record expires — no cleanup pass and no stale numbers. A worker
 * holding no pool writes nothing at all, so an application that never touches PPA
 * pays exactly zero.
 *
 * Set `PPA_POOL_TELEMETRY` to the publish interval in seconds, or `0` to disable.
 */
final class PoolTelemetry
{
    /** Store folder holding one record per worker. */
    private const string STORE = 'ppa.pool';

    /** Publish interval when `PPA_POOL_TELEMETRY` is unset, in seconds. */
    private const float DEFAULT_INTERVAL = 5.0;

    /** Swoole timer id of the publisher in this worker, or null when not running. */
    private static ?int $timerId = null;

    /**
     * Publish interval in seconds from `PPA_POOL_TELEMETRY`; `0.0` disables telemetry.
     * Values below one second are raised to one — this is telemetry, not a heartbeat.
     */
    public static function interval(): float
    {
        $raw = env('PPA_POOL_TELEMETRY');
        $val = $raw === null || $raw === '' ? self::DEFAULT_INTERVAL : (float) $raw;

        return $val <= 0.0 ? 0.0 : max(1.0, $val);
    }

    /**
     * Starts publishing this worker's pool utilisation. Call once per worker (from the
     * server's `workerStart`); a no-op when telemetry is disabled, Swoole is absent, or
     * this worker already publishes.
     */
    public static function start(int $workerId): void
    {
        $interval = self::interval();
        if (self::$timerId !== null || $interval <= 0.0 || !extension_loaded('swoole')) {
            return;
        }

        $ttl = (int) ceil($interval * 3);
        self::$timerId = \Swoole\Timer::tick(
            (int) ($interval * 1000),
            static fn() => self::publish($workerId, $ttl),
        );
    }

    /** Stops publishing and drops this worker's record. */
    public static function stop(int $workerId): void
    {
        if (self::$timerId === null) {
            // Never published, so there is no record to drop — and asking for the store
            // would create its directory in an application that has no pool at all.
            return;
        }

        if (extension_loaded('swoole')) {
            \Swoole\Timer::clear(self::$timerId);
        }
        self::$timerId = null;

        try {
            self::store()->del(self::recordKey($workerId));
        } catch (\Throwable) {
            // Telemetry must never break a shutdown.
        }
    }

    /**
     * Reads every worker record still alive, newest state as each worker last published
     * it. Expired records (dead workers) are skipped by the store's TTL.
     *
     * @return list<array{worker: int, at: int, pools: array<string, array{total: int, idle: int, active: int, maximum: int}>}>
     */
    public static function snapshot(): array
    {
        try {
            $store = self::store();
        } catch (\Throwable) {
            return [];
        }

        $records = [];
        foreach ($store->keys() as $key) {
            $record = $store->read($key);
            if (is_array($record) && isset($record['worker'], $record['pools'])) {
                $records[] = $record;
            }
        }

        usort($records, static fn(array $a, array $b): int => $a['worker'] <=> $b['worker']);

        return $records;
    }

    /**
     * Aggregates {@see snapshot()} across workers, per config — the fleet-wide view a
     * single actuator response cannot give.
     *
     * `saturated` counts the workers whose pool for that config is fully handed out.
     * It is deliberately **not** derived from the summed totals: a borrow queues on
     * its own worker's pool, so one saturated worker is a real stall even while the
     * fleet as a whole looks to have slack.
     *
     * @return array<string, array{total: int, idle: int, active: int, maximum: int, workers: int, saturated: int}>
     */
    public static function aggregate(): array
    {
        $out = [];
        foreach (self::snapshot() as $record) {
            foreach ($record['pools'] as $config => $stat) {
                $acc = $out[$config] ??= [
                    'total' => 0, 'idle' => 0, 'active' => 0, 'maximum' => 0, 'workers' => 0, 'saturated' => 0,
                ];
                $saturated = $stat['maximum'] > 0 && $stat['active'] >= $stat['maximum'];
                $out[$config] = [
                    'total'     => $acc['total'] + $stat['total'],
                    'idle'      => $acc['idle'] + $stat['idle'],
                    'active'    => $acc['active'] + $stat['active'],
                    'maximum'   => $acc['maximum'] + $stat['maximum'],
                    'workers'   => $acc['workers'] + 1,
                    'saturated' => $acc['saturated'] + ($saturated ? 1 : 0),
                ];
            }
        }

        return $out;
    }

    // ── internals ──────────────────────────────────────────────────────────────

    /**
     * Writes this worker's current utilisation. A worker holding no pool writes
     * nothing — an application that never touches PPA leaves no records behind.
     */
    private static function publish(int $workerId, int $ttl): void
    {
        try {
            $pools = PpaConnectionPool::stats();
            if ($pools === []) {
                return;
            }

            self::store()->write(
                self::recordKey($workerId),
                ['worker' => $workerId, 'at' => time(), 'pools' => $pools],
                time() + $ttl,
            );
        } catch (\Throwable) {
            // Telemetry is best-effort: a failed write must never disturb the worker.
        }
    }

    private static function recordKey(int $workerId): string
    {
        return 'worker.' . $workerId;
    }

    /** Non-hashed keys so {@see FileStorage::keys()} round-trips back into `read()`. */
    private static function store(): FileStorage
    {
        return Kernel::runnable(self::STORE, false);
    }
}
