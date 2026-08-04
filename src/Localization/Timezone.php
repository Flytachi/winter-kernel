<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Localization;

use Flytachi\Winter\Kernel\Core\RequestLocal;

/**
 * The timezone of the current request — the coroutine-safe counterpart of PHP's
 * process-wide `date_default_timezone_set()`.
 *
 * PHP keeps its default timezone in an engine global. Under Swoole every concurrent
 * request in a worker shares that global, so a request that sets its client's timezone
 * and then yields on I/O can resume to find another request's value in place. Measured,
 * not reasoned: a request from `Asia/Tashkent` yielded, a request from `Europe/London`
 * set its own, and the first one read `Europe/London` on resume — and passed it to the
 * database session for its own query.
 *
 * Here the value lives in {@see RequestLocal}, so concurrent requests cannot see each
 * other's. {@see \Flytachi\Winter\Kernel\Http\Middleware\ClientTimezoneMiddleware}
 * stores it; anything that formats a date on behalf of the request should read it:
 *
 * ```
 * $when = new \DateTimeImmutable('now', new \DateTimeZone(Timezone::current()));
 * ```
 *
 * **`date()` and `new DateTime()` without an explicit zone still read the engine
 * global** — no library can change that, it is how PHP works. They remain safe only
 * while concurrent requests share one timezone. Pass {@see current()} explicitly where
 * the answer must belong to the requesting user.
 */
final class Timezone
{
    private const string KEY = 'winter.timezone';

    private function __construct()
    {
    }

    /** Sets the timezone of the current request. */
    public static function set(string $timezone): void
    {
        RequestLocal::set(self::KEY, $timezone);
    }

    /**
     * The current request's timezone, falling back to `TIME_ZONE` from the environment
     * (`UTC` when unset) — so a caller never has to check whether anything was stored.
     */
    public static function current(): string
    {
        $stored = RequestLocal::get(self::KEY);

        return is_string($stored) && $stored !== ''
            ? $stored
            : (string) env('TIME_ZONE', 'UTC');
    }

    /** Whether this request carries a timezone of its own. */
    public static function isSet(): bool
    {
        return is_string(RequestLocal::get(self::KEY));
    }

    /** Drops the request's timezone; {@see current()} falls back to the environment. */
    public static function reset(): void
    {
        RequestLocal::forget(self::KEY);
    }
}
