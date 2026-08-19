<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Middleware;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Stereotype\Middleware;
use Flytachi\Winter\Kernel\Localization\Timezone;

/**
 * Applies the request's client timezone (`HttpRequest::getClientTimezone()`) for the
 * duration of that request, falling back to `env('TIME_ZONE', 'UTC')` when the header
 * is absent or names no real zone.
 *
 * The value is stored in two places, and the difference matters:
 *
 * - {@see Timezone} — **the source of truth.** Coroutine-local, so concurrent requests
 *   cannot overwrite each other. Everything the framework does on the request's behalf
 *   reads this, including the timezone of the database session
 *   ({@see \Flytachi\Winter\Ppa\Pool\PpaConnectionPool}).
 * - `date_default_timezone_set()` — **a convenience, safe under one condition only.**
 *   It is a PHP engine global shared by every coroutine in the worker, so a bare
 *   `date()` or `new DateTime()` reflects whichever request wrote last. That is correct
 *   while concurrent requests share a timezone and wrong the moment they do not; no
 *   library can fix it, that is how PHP stores the default.
 *
 * So read {@see Timezone::current()} wherever the answer must belong to the requesting
 * user, and treat the global as a best-effort default for code not yet adapted:
 *
 * ```
 * $when = new \DateTimeImmutable('now', new \DateTimeZone(Timezone::current()));
 * ```
 *
 * Swoole note: if the handler throws, `after()` does not run. The coroutine-local value
 * dies with the coroutine either way, but the engine global keeps the client's value
 * until the next request through this middleware resets it — one more reason not to
 * lean on it.
 *
 * Usage:
 *   #[ClientTimezoneMiddleware]
 *   class ReportController extends Controller { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class ClientTimezoneMiddleware extends Middleware
{
    public function before(HttpRequest $request, HttpResponse $response): void
    {
        $tz = (string) ($request->getClientTimezone() ?? env('TIME_ZONE', 'UTC'));

        Timezone::set($tz);
        date_default_timezone_set($tz);
    }

    public function after(mixed $result): mixed
    {
        date_default_timezone_set((string) env('TIME_ZONE', 'UTC'));
        return $result;
    }
}
