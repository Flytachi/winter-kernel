<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\App\Attribute\Bean;
use Flytachi\Winter\Kernel\App\Attribute\Configuration;
use Flytachi\Winter\Kernel\App\Attribute\Value;
use Flytachi\Winter\Kernel\App\Scope;
use Flytachi\Winter\Kernel\Collector\ConfigurationCollector;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ConfigurationCollectorTest extends TestCase
{
    private function collect(Container $c): void
    {
        new ConfigurationCollector($c)->collect(
            BeansFixtureConfig::class,
            new ReflectionClass(BeansFixtureConfig::class),
        );
    }

    public function test_singleton_bean_keyed_by_return_type(): void
    {
        $c = Container::init();
        $this->collect($c);

        $a = $c->make(CacheContract::class);
        $b = $c->make(CacheContract::class);

        self::assertInstanceOf(RedisCacheFixture::class, $a);
        self::assertSame($a, $b, 'a #[Bean] defaults to singleton scope');
    }

    public function test_transient_bean_returns_fresh_instances(): void
    {
        $c = Container::init();
        $this->collect($c);

        $q1 = $c->make(QueryBuilderFixture::class);
        $q2 = $c->make(QueryBuilderFixture::class);

        self::assertNotSame($q1, $q2, 'Scope::Transient yields a new instance each resolve');
    }

    public function test_value_parameter_uses_default_when_env_unset(): void
    {
        unset($_ENV['FIXTURE_CACHE_URL']);
        $c = Container::init();
        $this->collect($c);

        $cache = $c->make(CacheContract::class);
        self::assertSame('default-url', $cache->url);
    }

    public function test_value_parameter_reads_env(): void
    {
        $_ENV['FIXTURE_CACHE_URL'] = 'redis://from-env';
        try {
            $c = Container::init();
            $this->collect($c);                       // re-register clears the cached singleton
            $cache = $c->make(CacheContract::class);
            self::assertSame('redis://from-env', $cache->url);
        } finally {
            unset($_ENV['FIXTURE_CACHE_URL']);
        }
    }

    public function test_explicit_name_binding(): void
    {
        $c = Container::init();
        $this->collect($c);

        $primary = $c->make('primary.cache');
        self::assertInstanceOf(RedisCacheFixture::class, $primary);
        self::assertSame('primary', $primary->url);
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

interface CacheContract
{
}

final class RedisCacheFixture implements CacheContract
{
    public function __construct(public string $url)
    {
    }
}

final class QueryBuilderFixture
{
    private static int $counter = 0;
    public int $id;

    public function __construct()
    {
        $this->id = ++self::$counter;
    }
}

#[Configuration]
final class BeansFixtureConfig
{
    #[Bean]
    public function cache(#[Value('FIXTURE_CACHE_URL', 'default-url')] string $url): CacheContract
    {
        return new RedisCacheFixture($url);
    }

    #[Bean(scope: Scope::Transient)]
    public function query(): QueryBuilderFixture
    {
        return new QueryBuilderFixture();
    }

    #[Bean(name: 'primary.cache')]
    public function primaryCache(): RedisCacheFixture
    {
        return new RedisCacheFixture('primary');
    }
}
