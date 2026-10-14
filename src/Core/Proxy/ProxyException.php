<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core\Proxy;

/**
 * Thrown when a class or method cannot be proxied.
 *
 * Always a contract violation in application code — a final method, an
 * unsupported return type, a default value that cannot be reproduced — and
 * therefore raised while proxies are generated, never while serving traffic.
 *
 * Each feature that proxies methods throws its own subclass, so a caller can tell
 * an `#[Async]` problem from a caching one without reading the message.
 */
class ProxyException extends \LogicException
{
    /**
     * @param string $subject Fully qualified method or class the problem belongs to.
     * @param string $problem What is wrong, phrased as a statement.
     * @param string $remedy What the developer should do instead.
     */
    public static function of(string $subject, string $problem, string $remedy): static
    {
        return new static(sprintf('%s: %s. %s', $subject, $problem, $remedy));
    }
}
