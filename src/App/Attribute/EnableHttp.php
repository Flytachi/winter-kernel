<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

use Flytachi\Winter\Kernel\App\Component;

/**
 * Enables the HTTP server — one {@see \Flytachi\Winter\Kernel\App\Component::http()} in the
 * manifest. Declared on the {@see \Flytachi\Winter\Kernel\WinterApplication} class.
 *
 * The server is a component like the others: it runs in a process of its own under the
 * application's supervisor, and follows the same rules when it cannot run. It carries no
 * host or port — the bind address is deployment configuration, so it lives in `.env`, a
 * {@see \Flytachi\Winter\Kernel\App\Config\ServerConfigurer} or `--host`/`--port`
 * (fallback `0.0.0.0:8000`).
 *
 * ```
 * #[EnableHttp]
 * final class App extends WinterApplication { ... }
 * ```
 *
 * @link https://winterframe.net/docs/components What the HTTP component runs, and headless mode
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EnableHttp
{
    /**
     * @param bool $critical Whether the application stops when the server cannot run (the
     *                       default); false leaves the rest running without it.
     * @param int $retries Restarts after it crashed while serving, within 30 minutes.
     *                     A failure to start — a port in use — is never retried.
     * @param int $startTimeout Seconds to bind its port and start; 0 waits for good.
     */
    public function __construct(
        public bool $critical = true,
        public int $retries = Component::RETRIES,
        public int $startTimeout = Component::START_TIMEOUT,
    ) {
    }
}
