<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Config;

use Flytachi\Winter\K2\App\ApplicationArguments;

/**
 * Empty-default base for {@see WebConfigurer} — the winter analogue of Spring's
 * `WebMvcConfigurerAdapter`. Extend it and override only the concern you care
 * about; the other stays a no-op.
 *
 * ```
 * final class WebConfig extends WebConfigurerAdapter
 * {
 *     public function configureServer(ServerSettings $server, ApplicationArguments $args): void
 *     {
 *         $server->port($args->int('port', 8000))->workers(swoole_cpu_num() * 2);
 *     }
 * }
 * ```
 */
abstract class WebConfigurerAdapter implements WebConfigurer
{
    public function configureCors(CorsRegistry $cors): void
    {
    }

    public function configureServer(ServerSettings $server, ApplicationArguments $args): void
    {
    }
}
