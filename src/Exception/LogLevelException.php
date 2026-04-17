<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Exception;

/**
 * Marker interface for exceptions that carry their own PSR-3 log level.
 *
 * Router::logException() checks for this interface first, so any exception
 * implementing it will be logged at the level it declares — overriding the
 * default warning/error heuristic based on HTTP code.
 */
interface LogLevelException
{
    /** @return \Psr\Log\LogLevel::* */
    public function getLogLevel(): string;
}
