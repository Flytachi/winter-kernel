<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Collector;

use Flytachi\Winter\Base\Exception\ExceptionLogLevel;
use Psr\Log\LogLevel;

/**
 * A `#[Singleton]` can reach a `#[Request]` bean, so the application refuses to start.
 *
 * Refusing is the point. The alternative is not "it works" but "it leaks quietly": the
 * singleton keeps whichever request-scoped object existed when it was built, and every
 * later request keeps seeing it. An outage on deploy is recoverable in minutes; serving
 * one user's data to another is not.
 */
final class ScopeConflictException extends \RuntimeException implements ExceptionLogLevel
{
    /** @param list<string> $conflicts */
    public static function of(array $conflicts): self
    {
        $paths = implode("\n  ", $conflicts);

        return new self(<<<TEXT
            A #[Singleton] depends on a #[Request] bean, directly or through other classes:

              {$paths}

            A singleton is built once per worker and its dependencies are resolved at that
            moment, so the request-scoped object it captured belongs to whichever request
            came first — every later request would keep seeing that one.

            Fix it in one of these ways:
              · drop #[Singleton] from the holder, so it is built per request (the default);
              · resolve the request-scoped bean where it is used, not as a property;
              · make the dependency stateless and give it #[Singleton] too.
            TEXT);
    }

    public function getLogLevel(): string
    {
        return LogLevel::CRITICAL;
    }
}
