<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

use Flytachi\Winter\Kernel\App\Component;

/**
 * Enables the HTTP server — one {@see \Flytachi\Winter\Kernel\App\Component::http()} in the
 * manifest. Declared on the {@see \Flytachi\Winter\Kernel\WinterApplication} class.
 *
 * The server is a component like the others: it runs in a process of its own under the
 * application's supervisor, and follows the same rules when it cannot run.
 *
 * Its address is declared here, next to the server, like a WebSocket's. Where it binds,
 * the later word wins: this declaration, then `--http.port` / `--http.host` at launch,
 * then a {@see \Flytachi\Winter\Kernel\App\Config\ServerConfigurer} — code that sets
 * the address on purpose.
 *
 * ```
 * #[EnableHttp]                                 // 0.0.0.0:8000
 * #[EnableHttp(port: 8080, host: '127.0.0.1')]  // local only, behind a proxy on the same host
 * final class App extends WinterApplication { ... }
 * ```
 *
 * @link https://winterframe.net/docs/components What the HTTP component runs, and headless mode
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EnableHttp
{
    /**
     * @param int $port The port it listens on.
     * @param string $host The interface to bind; `127.0.0.1` keeps it local, `::` takes
     *                     IPv6 and IPv4 clients alike.
     * @param bool $critical Whether the application stops when the server cannot run (the
     *                       default); false leaves the rest running without it.
     * @param int $retries Restarts after it crashed while serving, within 30 minutes.
     *                     A failure to start — a port in use — is never retried.
     * @param int $startTimeout Seconds to bind its port and start; 0 waits for good.
     */
    public function __construct(
        public int $port = 8000,
        public string $host = '0.0.0.0',
        public bool $critical = true,
        public int $retries = Component::RETRIES,
        public int $startTimeout = Component::START_TIMEOUT,
    ) {
    }
}
