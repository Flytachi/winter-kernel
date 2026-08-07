<?php

declare(strict_types=1);

use Flytachi\Winter\Kernel\App\Attribute\EnableActuator;
use Flytachi\Winter\Kernel\App\Attribute\EnableWeb;
use Flytachi\Winter\Kernel\WinterApplication;

require __DIR__ . '/vendor/autoload.php';

/**
 * The dev playground application.
 *
 * This file is the whole bootstrap: load the autoloader, declare what the application
 * contains. There are no configuration hooks to override any more — everything else is
 * an ordinary class the scanner finds:
 *
 *   #[Configuration] / #[Bean]  → DI factories
 *   WebConfigurer               → host, port, Swoole tuning, CORS, static files
 *   LoggingConfigurer           → extra log channels
 *   #[Import('pkg', '/prefix')] → plugin packages
 *
 * Entry points:
 *   php call                      → console (bare = help)
 *   php call run [dev]            → bring the application up (Swoole; `dev` = watcher)
 *   php call daemon  <dot.Class> start [-d] | stop | status
 *   php call process <dot.Class> start [-d] | stop | status
 */
#[EnableWeb]
#[EnableActuator]
// #[EnableAsync]                                    // proxy #[Async] methods
// #[EnableScheduler]                                // run Main\Schedule\DemoTasks beside the server
// #[EnableProcess(\Main\Process\SendProc::class)]    // a worker running beside the server
// #[EnableDaemon(\Main\Process\StableDaemon::class)] // a supervised fleet beside the server
final class Application extends WinterApplication
{
    public static function main(array $argv): never
    {
        parent::run($argv);
    }
}
