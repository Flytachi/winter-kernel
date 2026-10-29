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
        Kernel::init(pathRoot: __DIR__);

        // Fixture only: no setting moves `.winter`, but this server must not write into
        // the repository, and each test's server needs state of its own — a previous one
        // that did not stop would otherwise refuse the next as "already running". The
        // test passes the directory so it can clean up; the fallback keeps the fixture
        // runnable by hand.
        $state = getenv('WK_SERVE_STORAGE') ?: sys_get_temp_dir() . '/wk_serve_' . getmypid();
        Kernel::$pathWinter = $state . '/.winter';
        Kernel::$pathCache = Kernel::$pathWinter . '/cache';
        Kernel::$pathBuild = Kernel::$pathWinter . '/build';
        Kernel::$pathRunnable = Kernel::$pathWinter . '/runnable';
        Kernel::$pathLogs = $state . '/logs';
    }

    public static function main(array $argv): never
    {
        parent::run($argv);
    }
}
