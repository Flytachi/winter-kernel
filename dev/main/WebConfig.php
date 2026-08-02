<?php

declare(strict_types=1);

namespace Main;

use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\App\Config\ServerSettings;
use Flytachi\Winter\Kernel\App\Config\WebConfigurerAdapter;

/**
 * Web configuration, found by the scan — no registration needed.
 *
 * Static files are opt-in: drop the call below and nothing is served, which is what an
 * API-only service wants. The directory is the URL root, so
 * `resources/static/winter/logo.svg` is reachable at `/winter/logo.svg`.
 */
final class WebConfig extends WebConfigurerAdapter
{
    public function configureServer(ServerSettings $server, ApplicationArguments $args): void
    {
        $server->staticPath('resources/static');
    }
}
