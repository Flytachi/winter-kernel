<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\WebSocket;

/**
 * A {@see WebSocket} cannot start as configured — no port, no ext-swoole to run on, or
 * a {@see WebSocket::\$tls} certificate it cannot serve with.
 */
class WebSocketConfigException extends \LogicException
{
}
