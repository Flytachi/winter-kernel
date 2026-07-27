<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Config;

/**
 * Web-tier configuration contract — the winter analogue of Spring's
 * `WebMvcConfigurer`. Any class implementing it is discovered on scan and invoked
 * at boot; there is no registration.
 *
 * It carries two concerns of the web tier:
 *   - {@see configureCors()} — the global CORS policy (request-time);
 *   - {@see configureServer()} — Swoole server tuning (master, before workers fork).
 *
 * Implement this interface directly to handle both, or extend
 * {@see WebConfigurerAdapter} to override only the one you need.
 */
interface WebConfigurer
{
    public function configureCors(CorsRegistry $cors): void;

    public function configureServer(ServerSettings $server): void;
}
