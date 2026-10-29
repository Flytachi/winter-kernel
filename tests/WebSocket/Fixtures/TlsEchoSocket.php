<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\WebSocket\Connection;
use Flytachi\Winter\Kernel\WebSocket\Frame;
use Flytachi\Winter\Kernel\WebSocket\WebSocket;

/**
 * A `wss://` socket on the HTTP server's certificate (`SERVER_TLS_CERT` /
 * `SERVER_TLS_KEY`): answers every message with the scheme its handshake saw and the
 * message itself.
 */
final class TlsEchoSocket extends WebSocket
{
    protected bool $tls = true;
    protected float $grace = 2.0;

    protected function onHandshake(Connection $conn, HttpRequest $request): bool
    {
        $conn->set('scheme', $request->getScheme());
        return true;
    }

    protected function onMessage(Connection $conn, Frame $frame): void
    {
        $conn->send($conn->get('scheme') . ':' . $frame->data);
    }
}
