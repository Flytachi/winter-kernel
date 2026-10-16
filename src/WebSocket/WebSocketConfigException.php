<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\WebSocket;

/**
 * A {@see WebSocket} cannot start as configured — no port, or no ext-swoole to run on.
 */
class WebSocketConfigException extends \LogicException
{
}
