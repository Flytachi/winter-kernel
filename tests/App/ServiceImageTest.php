<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\ServiceImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a service needs in the image, and how several of them become one Dockerfile
 * block: each thing once, and two services wanting one thing two ways is an error.
 */
final class ServiceImageTest extends TestCase
{
    public function test_one_service_makes_one_block(): void
    {
        $image = new ServiceImage(
            packages: ['postgresql18', 'postgresql18-client'],
            directories: ['/var/lib/postgresql/data' => 0700, '/run/postgresql' => 0755],
            volumes: ['postgres-data' => '/var/lib/postgresql/data'],
            env: ['PGDATA' => '/var/lib/postgresql/data'],
        );

        self::assertSame(<<<'DOCKER'
            # winter:services — written by `call service docker`; change the services, not this block
            USER root
            RUN apk add --no-cache postgresql18 postgresql18-client \
                && mkdir -p /var/lib/postgresql/data /run/postgresql \
                && chown -R winter:winter /var/lib/postgresql/data /run/postgresql \
                && chmod 700 /var/lib/postgresql/data \
                && chmod 755 /run/postgresql
            USER winter
            ENV PGDATA=/var/lib/postgresql/data
            # winter:services end

            DOCKER, $image->dockerfile());
    }

    public function test_services_merge_into_one_layer_each_thing_once(): void
    {
        $merged = ServiceImage::merge(
            new ServiceImage(packages: ['postgresql18', 'tzdata'], directories: ['/run/postgresql' => 0755]),
            new ServiceImage(packages: ['redis', 'tzdata'], directories: ['/var/lib/redis' => 0755, '/run/postgresql' => 0755],
                run: ['echo one']),
            new ServiceImage(run: ['echo one', 'echo two']),
        );

        self::assertSame(['postgresql18', 'tzdata', 'redis'], $merged->packages);
        self::assertSame(['/run/postgresql' => 0755, '/var/lib/redis' => 0755], $merged->directories);
        self::assertSame(['echo one', 'echo two'], $merged->run, 'in order, each once');
        self::assertSame(1, substr_count($merged->dockerfile(), 'apk add'));
    }

    /** @return iterable<string, array{ServiceImage, ServiceImage, string}> */
    public static function conflicts(): iterable
    {
        yield 'a directory with two modes' => [
            new ServiceImage(directories: ['/data' => 0700]),
            new ServiceImage(directories: ['/data' => 0755]),
            'directory /data is wanted with mode 0700 and 0755.',
        ];
        yield 'a volume name for two paths' => [
            new ServiceImage(volumes: ['data' => '/a']),
            new ServiceImage(volumes: ['data' => '/b']),
            'volume data is wanted for /a and /b.',
        ];
        yield 'a path under two volume names' => [
            new ServiceImage(volumes: ['one' => '/a']),
            new ServiceImage(volumes: ['two' => '/a']),
            '/a is wanted as volume one and two.',
        ];
        yield 'a variable with two values' => [
            new ServiceImage(env: ['TZ' => 'UTC']),
            new ServiceImage(env: ['TZ' => 'Asia/Tashkent']),
            'TZ is wanted as "UTC" and "Asia/Tashkent".',
        ];
    }

    #[DataProvider('conflicts')]
    public function test_two_services_wanting_one_thing_two_ways_is_an_error(
        ServiceImage $one,
        ServiceImage $two,
        string $message,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ServiceImage::merge($one, $two);
    }

    public function test_nothing_to_install_makes_no_root_step(): void
    {
        self::assertSame(
            "# winter:services — written by `call service docker`; change the services, not this block\n"
            . "ENV TZ=UTC\n# winter:services end\n",
            (new ServiceImage(env: ['TZ' => 'UTC']))->dockerfile(),
        );
        self::assertStringContainsString('ENV GREETING="hello world"', (new ServiceImage(env: ['GREETING' => 'hello world']))->dockerfile());
    }

    /** @return iterable<string, array{\Closure(): ServiceImage}> */
    public static function badImages(): iterable
    {
        yield 'a package with a space' => [static fn() => new ServiceImage(packages: ['postgresql 18'])];
        yield 'a relative directory' => [static fn() => new ServiceImage(directories: ['data' => 0700])];
        yield 'a path climbing up' => [static fn() => new ServiceImage(directories: ['/app/../etc' => 0700])];
        yield 'a mode out of range' => [static fn() => new ServiceImage(directories: ['/data' => 01000])];
        yield 'a volume name with a slash' => [static fn() => new ServiceImage(volumes: ['a/b' => '/data'])];
        yield 'a variable name with a dash' => [static fn() => new ServiceImage(env: ['MY-VAR' => 'x'])];
        yield 'a value on two lines' => [static fn() => new ServiceImage(env: ['X' => "a\nb"])];
        yield 'an empty command' => [static fn() => new ServiceImage(run: [' '])];
    }

    #[DataProvider('badImages')]
    public function test_what_the_dockerfile_cannot_take_is_refused(\Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }
}
