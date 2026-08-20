<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Config;

/**
 * Web-tier configuration contract — the winter analogue of Spring's
 * `WebMvcConfigurer`. Any class implementing it is discovered on scan and invoked
 * at boot; there is no registration.
 *
 * It carries the request-time policy of the web tier — today the global CORS rules.
 * Every implementation found is applied, and the contributions add up into one registry,
 * so an imported package may bring its own rules alongside the application's.
 *
 * The order is defined rather than incidental: imported packages are applied first, in
 * the order they were imported, and the application last. Where two contributors touch
 * the same setting, the application therefore wins — it owns the process, and a package
 * should never be able to overrule it by virtue of being scanned earlier.
 *
 * The bind address and Swoole tuning are NOT here: they are one object rather than a set,
 * so they cannot be composed. See {@see ServerConfigurer}, which only the application may
 * implement.
 *
 * @link https://winterframe.net/docs/web-configuration The web-layer configuration contract
 */
interface WebConfigurer
{
    /**
     * @param CorsRegistry $cors Shared registry; add rules, do not assume it is empty.
     */
    public function configureCors(CorsRegistry $cors): void;
}
