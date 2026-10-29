<?php

declare(strict_types=1);

use Flytachi\Winter\Kernel\App\Attribute\EnableActuator;
use Flytachi\Winter\Kernel\App\Attribute\EnableAsync;
use Flytachi\Winter\Kernel\App\Attribute\EnableCaching;
use Flytachi\Winter\Kernel\App\Attribute\EnableDaemon;
use Flytachi\Winter\Kernel\App\Attribute\EnableHttp;
use Flytachi\Winter\Kernel\App\Attribute\EnableProcess;
use Flytachi\Winter\Kernel\App\Attribute\EnableScheduler;
use Flytachi\Winter\Kernel\App\Components;
use Flytachi\Winter\Kernel\WinterApplication;

require __DIR__ . '/vendor/autoload.php';

/**
 * The dev playground application.
 *
 * The kernel is installed from `../` as a symlink, so every change in the repository is
 * live here without `composer update`. Uncomment what you want to try; see README.md.
 *
 *   php call                     console (bare = help)
 *   php call run [dev]           bring the application up as PID 1 (`dev` = watcher)
 *   php call app info|status|stop
 *   php call process|daemon|schedule …
 */
#[EnableHttp(port: 8000)]
#[EnableActuator(expose: ['*'])]
// #[EnableAsync]
// #[EnableCaching]
// #[EnableScheduler]
// #[EnableProcess(\Main\Process\SendProc::class)]
// #[EnableDaemon(\Main\Process\StableDaemon::class)]
final class Application extends WinterApplication
{
    public static function main(array $argv): never
    {
        parent::run($argv);
    }

    protected static function components(Components $components): void
    {
        // $components->process(\Main\Process\LongDemo::class, critical: false);
        // $components->daemon(\Main\Process\FleetDaemon::class);
        // $components->command(['sleep', '30'], name: 'sleeper', critical: false);
    }
}
