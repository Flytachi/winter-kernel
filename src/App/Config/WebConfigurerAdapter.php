<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\App\Config;

/**
 * Empty-default base for {@see WebConfigurer} — the winter analogue of Spring's
 * `WebMvcConfigurerAdapter`. Extend it and override only the concern you care
 * about; the other stays a no-op.
 *
 * ```
 * final class WebConfig extends WebConfigurerAdapter
 * {
 *     public function configureCors(CorsRegistry $cors): void
 *     {
 *         $cors->allowedOrigins('https://app.example.com')->allowCredentials();
 *     }
 * }
 * ```
 */
abstract class WebConfigurerAdapter implements WebConfigurer
{
    public function configureCors(CorsRegistry $cors): void
    {
    }

    public function configureServer(ServerSettings $server): void
    {
    }
}
