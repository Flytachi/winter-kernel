<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Config;

use Flytachi\Winter\Kernel\App\ApplicationArguments;

/**
 * Empty-default base for both web-tier contracts — the winter analogue of Spring's
 * `WebMvcConfigurerAdapter`. Extend it and override only the concern you care about;
 * the other stays a no-op.
 *
 * ```
 * final class WebConfig extends WebConfigurerAdapter
 * {
 *     public function configureServer(ServerSettings $server, ApplicationArguments $args): void
 *     {
 *         $server->port($args->int('port', 8000))->staticPath('resources/static');
 *     }
 * }
 * ```
 *
 * Because it implements {@see ServerConfigurer}, a class extending it belongs to the
 * application: the same class inside an imported package is refused at boot. A package
 * that only needs CORS should implement {@see WebConfigurer} directly.
 *
 * @link https://winterframe.net/docs/web-configuration Overriding only what you need: CORS or server settings
 */
abstract class WebConfigurerAdapter implements WebConfigurer, ServerConfigurer
{
    public function configureCors(CorsRegistry $cors): void
    {
    }

    public function configureServer(ServerSettings $server, ApplicationArguments $args): void
    {
    }
}
