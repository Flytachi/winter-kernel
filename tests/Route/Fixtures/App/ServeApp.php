<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route\Fixtures\App;

use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\App\Attribute\EnableWeb;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\WinterApplication;

/**
 * The fixture application {@see \Flytachi\Winter\Kernel\Tests\Route\ServeHttpTest} boots
 * for real — the same class shape a project writes, sharing this directory with the
 * controller so the scan finds it exactly as it would in an application.
 *
 * `configure()` is overridden only to keep runtime files out of the repository: the
 * default root would be this fixture directory, and the kernel would create
 * `storage/` inside it on first use.
 */
#[EnableWeb]
final class ServeApp extends WinterApplication
{
    protected static function configure(ApplicationArguments $args): void
    {
        Kernel::init(
            pathRoot: __DIR__,
            // The test passes the path so it can clean up afterwards; the fallback
            // keeps the fixture runnable by hand.
            pathStorage: getenv('WK_SERVE_STORAGE')
                ?: sys_get_temp_dir() . '/wk_serve_' . getmypid(),
        );
    }

    public static function main(array $argv): never
    {
        parent::run($argv);
    }
}
