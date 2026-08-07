<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Config;

use Flytachi\Winter\Kernel\App\ApplicationArguments;

/**
 * Web-tier configuration contract — the winter analogue of Spring's
 * `WebMvcConfigurer`. Any class implementing it is discovered on scan and invoked
 * at boot; there is no registration.
 *
 * It carries two concerns of the web tier:
 *   - {@see configureCors()} — the global CORS policy (request-time);
 *   - {@see configureServer()} — the bind address (host/port) and Swoole server
 *     tuning (master, before workers fork).
 *
 * {@see configureServer()} receives the parsed CLI arguments so the coder decides
 * where the bind address comes from — a `--port` flag, a custom flag, .env, or a
 * literal. The handle is pre-seeded with the framework default (`--host`/`--port`,
 * fallback `0.0.0.0:8000`), so leaving it untouched keeps that default.
 *
 * Implement this interface directly to handle both, or extend
 * {@see WebConfigurerAdapter} to override only the one you need.
 */
interface WebConfigurer
{
    public function configureCors(CorsRegistry $cors): void;

    public function configureServer(ServerSettings $server, ApplicationArguments $args): void;
}
