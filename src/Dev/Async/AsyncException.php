<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Async;

/**
 * Thrown when an {@see Async} method cannot be proxied.
 *
 * Always a contract violation in application code — a final method, an
 * unsupported return type, a default value that cannot be reproduced — and
 * therefore raised while proxies are generated, never while serving traffic.
 */
class AsyncException extends \LogicException
{
    /**
     * @param string $subject Fully qualified method or class the problem belongs to.
     * @param string $problem What is wrong, phrased as a statement.
     * @param string $remedy What the developer should do instead.
     */
    public static function of(string $subject, string $problem, string $remedy): self
    {
        return new self(sprintf('%s: %s. %s', $subject, $problem, $remedy));
    }
}
