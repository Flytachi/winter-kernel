<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route\Fixtures\App;

use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\App\Config\ServerConfigurer;
use Flytachi\Winter\Kernel\App\Config\ServerSettings;

/**
 * The trusted proxies of the served app, from the application's own variable — the way an
 * application takes them from its environment when it wants to. Unset: nobody trusted.
 */
final class ProxyConfig implements ServerConfigurer
{
    public function configureServer(ServerSettings $server, ApplicationArguments $args): void
    {
        $proxies = (string) (getenv('TEST_TRUSTED_PROXIES') ?: '');
        if ($proxies !== '') {
            $server->trustedProxies(...explode(',', $proxies));
        }
    }
}
