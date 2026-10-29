<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\WebSocket\WebSocket;

/**
 * Declares a {@see WebSocket} server as part of the application — produces one
 * {@see \Flytachi\Winter\Kernel\App\Component::websocket()} in the manifest. Declared
 * on the {@see \Flytachi\Winter\Kernel\WinterApplication} class; repeatable.
 *
 * ```
 * #[EnableHttp]
 * #[EnableWebSocket(ChatSocket::class, port: 8001)]
 * #[EnableWebSocket(NotifySocket::class, port: 8002, host: '127.0.0.1')]
 * final class App extends WinterApplication { ... }
 * ```
 *
 * One port serves one process: two sockets cannot share a port, not even with
 * different paths — routing by path is the proxy's job.
 *
 * @link https://winterframe.net/docs/websockets Running a WebSocket server with the application
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class EnableWebSocket
{
    /**
     * @param class-string<WebSocket> $class The WebSocket class to run.
     * @param int $port The port it listens on.
     * @param string $host The interface to bind; `127.0.0.1` keeps it local (behind a proxy on the same host),
     *                     `::` takes IPv6 and IPv4 clients alike.
     * @param bool $critical Whether the application stops when it cannot run (the default);
     *                       false leaves it down, marked failed, while the rest runs on.
     * @param int $retries Restarts after it crashed while running, within 30 minutes.
     *                     A failure to start is never retried.
     * @param int $startTimeout Seconds to become ready (to listen); 0 waits for good.
     */
    public function __construct(
        public string $class,
        public int $port,
        public string $host = '0.0.0.0',
        public bool $critical = true,
        public int $retries = Component::RETRIES,
        public int $startTimeout = Component::START_TIMEOUT,
    ) {
    }
}
