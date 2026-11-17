<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\App\Priority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What a frontend is served from and where, as a package describes it to the kernel. */
final class FrontSiteTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function dir(): string
    {
        $this->dir = sys_get_temp_dir() . '/wk_site_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        return $this->dir;
    }

    public function test_the_defaults_are_a_spa_at_the_root_not_critical_and_low(): void
    {
        $site = new FrontSite('frontend', '/x');

        self::assertSame('200.html', $site->shell);
        self::assertSame('/', $site->path);
        self::assertNull($site->port);
        self::assertSame([], $site->routes);
        self::assertTrue($site->exclusive);
        self::assertFalse($site->critical);
        self::assertSame(Priority::Low, $site->priority);
        self::assertTrue($site->overlap);
    }

    public function test_the_marker_is_the_shell_or_index(): void
    {
        self::assertSame('/x/200.html', (new FrontSite('frontend', '/x'))->marker());
        self::assertSame('/x/index.html', (new FrontSite('site', '/x', shell: null))->marker());
    }

    public function test_is_built_follows_the_marker_on_disk(): void
    {
        $dir = $this->dir();
        $site = new FrontSite('frontend', $dir);

        self::assertFalse($site->isBuilt());
        touch($dir . '/200.html');
        self::assertTrue($site->isBuilt());
    }

    public function test_has_route_matches_whole_segments(): void
    {
        $site = new FrontSite('admin', '/x', port: 9091, routes: ['/admin', '/auth']);

        self::assertTrue($site->hasRoute('/admin'));
        self::assertTrue($site->hasRoute('/admin/users'));
        self::assertTrue($site->hasRoute('/auth/login'));
        self::assertFalse($site->hasRoute('/administrator'));
        self::assertFalse($site->hasRoute('/'));
    }

    public function test_the_root_as_a_route_is_every_route(): void
    {
        $site = new FrontSite('admin', '/x', port: 9091, routes: ['/']);

        self::assertTrue($site->hasRoute('/'));
        self::assertTrue($site->hasRoute('/api/users'), 'every controller on its port');
    }

    public function test_a_trailing_slash_is_dropped(): void
    {
        $site = new FrontSite('admin', '/x/', path: '/admin/', port: 9091, routes: ['/api/']);

        self::assertSame('/admin', $site->path);
        self::assertSame(['/api'], $site->routes);
        self::assertSame('/x', $site->root);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'a name of other letters' => [['name' => 'Front End'], 'name must be lowercase'];
        yield 'a relative root' => [['root' => 'rel'], 'root must be an absolute path'];
        yield 'a path without a slash' => [['path' => 'admin'], 'path must start with /'];
        yield 'a port out of range' => [['port' => 70000], 'port must be 1–65535'];
        yield 'a route prefix without a slash' => [['port' => 9091, 'routes' => ['admin']], 'a route prefix starts with /'];
        yield 'routes without a port' => [['routes' => ['/admin']], 'routes need a port of its own'];
        yield 'a shell that is a path' => [['shell' => '../x.html'], 'shell is a file name'];
    }

    /** @param array<string, mixed> $parameters */
    #[DataProvider('refusals')]
    public function test_a_value_it_cannot_take_is_refused(array $parameters, string $why): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($why);
        new FrontSite(...['name' => 'frontend', 'root' => '/x', ...$parameters]);
    }
}
