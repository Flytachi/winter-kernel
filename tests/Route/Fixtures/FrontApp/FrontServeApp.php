<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route\Fixtures\FrontApp;

use Flytachi\Winter\Kernel\App\ApplicationArguments;
use Flytachi\Winter\Kernel\App\Attribute\EnableHttp;
use Flytachi\Winter\Kernel\App\Components;
use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\WinterApplication;

/**
 * An application with two frontends, for {@see \Flytachi\Winter\Kernel\Tests\Route\ServeFrontendTest}:
 * a site at the root of the HTTP port, and an admin on a port of its own with `/admin`.
 * `WK_FRONT_CASE` bends it: `overlap` (a frontend under a path a controller takes),
 * `same-port` (two frontends on one port), `http-port` (a frontend on the HTTP port's number).
 */
#[EnableHttp]
final class FrontServeApp extends WinterApplication
{
    protected static function configure(ApplicationArguments $args): void
    {
        self::paths();
    }

    /** Fixture only: state in the test's directory, the scan in this one. */
    public static function paths(): void
    {
        Kernel::init(pathRoot: __DIR__);
        $state = getenv('WK_SERVE_STORAGE') ?: sys_get_temp_dir() . '/wk_front_' . getmypid();
        Kernel::$pathWinter = $state . '/.winter';
        Kernel::$pathCache = Kernel::$pathWinter . '/cache';
        Kernel::$pathBuild = Kernel::$pathWinter . '/build';
        Kernel::$pathRunnable = Kernel::$pathWinter . '/runnable';
        Kernel::$pathLogs = $state . '/logs';
    }

    /** The HTTP server's port, as the test's server process passes it on. */
    private static function httpPort(): int
    {
        return (int) (getenv('WK_SERVE_PORT') ?: 8000);
    }

    protected static function components(Components $components): void
    {
        $root = (string) getenv('WK_FRONT_ROOT');
        $adminPort = (int) getenv('WK_FRONT_ADMIN_PORT');
        $case = (string) getenv('WK_FRONT_CASE');

        $components->frontend(new TestFrontend(
            $case === 'ssg'
                ? new FrontSite('site', $root . '/ssg', shell: null)
                : new FrontSite('site', $root . '/site'),
        ));
        $components->frontend(new TestFrontend(new FrontSite(
            'admin',
            $root . '/admin',
            port: $case === 'http-port' ? self::httpPort() : $adminPort,
            routes: ['/admin'],
        )));
        if ($case === 'same-port') {
            $components->frontend(new TestFrontend(new FrontSite('panel', $root . '/admin', port: $adminPort)));
        }
        if ($case === 'ws-port') {
            $components->websocket(\Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures\EchoSocket::class, port: $adminPort);
        }
        if ($case === 'overlap' || $case === 'overlap-silent') {
            $components->frontend(new TestFrontend(
                new FrontSite('taken', $root . '/site', path: '/taken', overlap: $case === 'overlap'),
            ));
        }
    }
}
