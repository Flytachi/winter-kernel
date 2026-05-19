<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Middleware;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Stereotype\Middleware;

/**
 * Sets date_default_timezone_set() from the request's client timezone
 * (HttpRequest::getClientTimezone()) for the duration of the request.
 *
 * Falls back to env('TIME_ZONE', 'UTC') when the client header is
 * absent or invalid. after() restores to the same canonical default.
 *
 * Swoole note: if the handler throws, after() is not invoked. The
 * worker's global TZ stays at the client's value until the next
 * request that passes through this middleware resets it. Apply
 * uniformly across routes — or skip the middleware and use
 * $request->getClientTimezone() explicitly in handlers.
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
        $tz = $request->getClientTimezone() ?? env('TIME_ZONE', 'UTC');
        date_default_timezone_set((string) $tz);
    }

    public function after(mixed $result): mixed
    {
        date_default_timezone_set((string) env('TIME_ZONE', 'UTC'));
        return $result;
    }
}
