<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Front;

use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\Http\Front\SiteMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Which frontend a request reaches, and which controller routes each port shows. */
final class SiteMapTest extends TestCase
{
    private const int HTTP = 8000;

    private static function site(string $name, mixed ...$more): FrontSite
    {
        return new FrontSite($name, '/build/' . $name, ...$more);
    }

    public function test_without_sites_every_route_is_visible_on_the_http_port(): void
    {
        $map = new SiteMap([], self::HTTP);

        self::assertTrue($map->isEmpty());
        self::assertTrue($map->routeVisible(self::HTTP, '/admin/x'));
        self::assertNull($map->siteFor(self::HTTP, '/'));
        self::assertSame([], $map->ports());
    }

    public function test_a_site_at_the_root_takes_every_path_left(): void
    {
        $map = new SiteMap([self::site('frontend')], self::HTTP);

        self::assertSame('frontend', $map->siteFor(self::HTTP, '/x')?->name);
        self::assertSame('frontend', $map->siteFor(self::HTTP, '/')?->name);
    }

    public function test_a_site_under_a_path_takes_only_that_path(): void
    {
        $map = new SiteMap([self::site('admin', path: '/admin')], self::HTTP);

        self::assertSame('admin', $map->siteFor(self::HTTP, '/admin/users')?->name);
        self::assertSame('admin', $map->siteFor(self::HTTP, '/admin')?->name);
        self::assertNull($map->siteFor(self::HTTP, '/x'));
        self::assertNull($map->siteFor(self::HTTP, '/administrator'));

        $both = new SiteMap([self::site('site'), self::site('admin', path: '/admin')], self::HTTP);
        self::assertSame('admin', $both->siteFor(self::HTTP, '/admin/users')?->name, 'the longest path wins');
        self::assertSame('site', $both->siteFor(self::HTTP, '/about')?->name);
    }

    public function test_routes_are_visible_on_the_sites_port_and_taken_off_the_http_port(): void
    {
        $map = new SiteMap([self::site('admin', port: 9091, routes: ['/admin'])], self::HTTP);

        self::assertTrue($map->routeVisible(9091, '/admin/users'));
        self::assertFalse($map->routeVisible(9091, '/api/deliveries'));
        self::assertFalse($map->routeVisible(self::HTTP, '/admin/users'));
        self::assertTrue($map->routeVisible(self::HTTP, '/api/deliveries'));
        self::assertSame('admin', $map->siteFor(9091, '/users')?->name);
        self::assertNull($map->siteFor(self::HTTP, '/users'), 'the admin is not on the HTTP port');
        self::assertSame([9091], $map->ports());
    }

    public function test_a_prefix_named_by_two_sites_is_open_on_both_ports(): void
    {
        $map = new SiteMap([
            self::site('admin', port: 9091, routes: ['/admin']),
            self::site('operator', port: 9092, routes: ['/admin']),
        ], self::HTTP);

        self::assertTrue($map->routeVisible(9091, '/admin/users'));
        self::assertTrue($map->routeVisible(9092, '/admin/users'));
        self::assertFalse($map->routeVisible(self::HTTP, '/admin/users'));
        self::assertSame([9091, 9092], $map->ports());
        self::assertSame([], $map->notes());
    }

    public function test_exclusive_false_opens_the_prefix_on_the_http_port_and_says_so(): void
    {
        $map = new SiteMap([
            self::site('admin', port: 9091, routes: ['/admin']),
            self::site('operator', port: 9092, routes: ['/admin'], exclusive: false),
        ], self::HTTP);

        self::assertTrue($map->routeVisible(self::HTTP, '/admin/users'));
        self::assertSame(['/admin is open on the HTTP port: "operator" has exclusive: false'], $map->notes());
    }

    public function test_a_prefix_of_another_site_is_not_visible_on_this_port(): void
    {
        $map = new SiteMap([
            self::site('admin', port: 9091, routes: ['/admin']),
            self::site('operator', port: 9092, routes: ['/operator']),
        ], self::HTTP);

        self::assertFalse($map->routeVisible(9091, '/operator/x'));
        self::assertFalse($map->routeVisible(9092, '/admin/x'));
    }

    public function test_an_unknown_port_shows_nothing(): void
    {
        $map = new SiteMap([self::site('admin', port: 9091, routes: ['/admin'])], self::HTTP);

        self::assertFalse($map->routeVisible(7000, '/admin/x'));
        self::assertNull($map->siteFor(7000, '/'));
    }

    /** @return iterable<string, array{list<FrontSite>, string}> */
    public static function conflicts(): iterable
    {
        yield 'two at the root of the HTTP port' => [
            [self::site('frontend'), self::site('site')],
            '"frontend" and "site" both take / on port 8000',
        ];
        yield 'two on one port of their own' => [
            [self::site('admin', port: 9091), self::site('panel', port: 9091)],
            '"admin" and "panel" both take port 9091',
        ];
        yield 'the HTTP server\'s port' => [
            [self::site('admin', port: 8000, routes: ['/admin'])],
            'port 8000 is the HTTP server\'s',
        ];
    }

    /** @param list<FrontSite> $sites */
    #[DataProvider('conflicts')]
    public function test_two_sites_in_one_place_are_refused(array $sites, string $why): void
    {
        $this->expectException(ApplicationConfigException::class);
        $this->expectExceptionMessage($why);
        new SiteMap($sites, self::HTTP);
    }

    public function test_the_root_as_a_route_closes_every_route_on_the_http_port(): void
    {
        $map = new SiteMap([new FrontSite('admin', '/b/admin', port: 9091, routes: ['/'])], 8000);

        self::assertTrue($map->routeVisible(9091, '/api/users'), 'its port shows every controller');
        self::assertFalse($map->routeVisible(8000, '/api/users'), 'and the HTTP port none: exclusive');
        self::assertFalse($map->routeVisible(8000, '/'));
    }
}
