<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Concurrent\Async;

use Flytachi\Winter\Kernel\Core\Proxy\ProxyException;

/**
 * Thrown when an {@see Async} method cannot be proxied.
 *
 * Always a contract violation in application code — a final method, an
 * unsupported return type, a default value that cannot be reproduced — and
 * therefore raised while proxies are generated, never while serving traffic.
 */
class AsyncException extends ProxyException
{
}
