<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\WinterApplication;
use PHPUnit\Framework\TestCase;

/** Where the banner says a frontend answers. */
final class BannerFrontRowTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    private static function rows(FrontSite $site): array
    {
        $rows = new \ReflectionMethod(WinterApplication::class, 'bannerRows');
        return $rows->invoke(null, [Component::frontend($site)], '0.0.0.0', 8080, 'http');
    }

    public function test_a_frontend_under_a_path_is_said_with_its_path(): void
    {
        self::assertContains(['front', 'admin http://0.0.0.0:8080/admin'], self::rows(new FrontSite('admin', '/b', path: '/admin')));
    }

    public function test_a_frontend_at_the_root_or_on_its_port(): void
    {
        self::assertContains(['front', 'site http://0.0.0.0:8080/'], self::rows(new FrontSite('site', '/b')));
        self::assertContains(['front', 'admin http://0.0.0.0:9091/'], self::rows(new FrontSite('admin', '/b', port: 9091)));
    }
}
