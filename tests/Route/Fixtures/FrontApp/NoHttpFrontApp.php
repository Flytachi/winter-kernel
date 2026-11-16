<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route\Fixtures\FrontApp;

use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\App\Components;
use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\WinterApplication;

/** A frontend and no HTTP server: refused at boot. */
final class NoHttpFrontApp extends WinterApplication
{
    protected static function configure(ApplicationArguments $args): void
    {
        FrontServeApp::paths();
    }

    protected static function components(Components $components): void
    {
        $components->command(['sleep', '30'], 'sleeper');
        $components->frontend(new TestFrontend(new FrontSite('site', (string) getenv('WK_FRONT_ROOT') . '/site')));
    }
}
