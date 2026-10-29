<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures;

use Flytachi\Winter\Kernel\WebSocket\Connection;
use Flytachi\Winter\Kernel\WebSocket\Frame;
use Flytachi\Winter\Kernel\WebSocket\WebSocket;

/** A `wss://` socket with a certificate of its own, named by `CHAT_TLS_CERT` / `CHAT_TLS_KEY`. */
final class OwnCertSocket extends WebSocket
{
    protected bool $tls = true;

    protected static function tlsFiles(): array
    {
        return ['cert' => $_ENV['CHAT_TLS_CERT'] ?? null, 'key' => $_ENV['CHAT_TLS_KEY'] ?? null];
    }

    protected function onMessage(Connection $conn, Frame $frame): void
    {
    }
}
