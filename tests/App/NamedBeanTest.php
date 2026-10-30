<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\App\Attribute\Bean;
use Flytachi\Winter\Kernel\App\Attribute\Configuration;
use Flytachi\Winter\Kernel\Collector\ConfigurationCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Beans with a name: reached by the name always, and by their type unless something
 * else owns the type. Before, a named bean was reachable by name only, so injecting its
 * type quietly built a new object that was none of the beans; and inside a #[Bean]
 * method, #[Inject('name')] was ignored.
 */
final class NamedBeanTest extends TestCase
{
    /** @param list<class-string> $configs In the order the scan meets them. */
    private function container(array $configs): Container
    {
        $c = Container::init();
        $collector = new ConfigurationCollector($c);
        foreach ($configs as $config) {
            $collector->collect($config, new ReflectionClass($config));
        }
        $collector->finish();
        return $c;
    }

    /** @return iterable<string, array{list<class-string>}> */
    public static function bothOrders(): iterable
    {
        yield 'unnamed first' => [[UnnamedClientConfig::class, NamedClientConfig::class]];
        yield 'named first' => [[NamedClientConfig::class, UnnamedClientConfig::class]];
    }

    /** Two clients, only one named: the unnamed one owns the type, whatever the order. */
    #[DataProvider('bothOrders')]
    public function test_an_unnamed_bean_owns_the_type(array $configs): void
    {
        $c = $this->container($configs);

        self::assertSame('default', $c->make(ClientFixture::class)->who);
        self::assertSame('notice', $c->make('notice.client')->who);
        self::assertSame('notice', $c->make(NoticeConsumer::class)->client->who);
        self::assertSame('default', $c->make(DefaultConsumer::class)->client->who);
    }

    public function test_a_lone_named_bean_is_its_type_too(): void
    {
        $c = $this->container([NamedClientConfig::class]);

        $byType = $c->make(ClientFixture::class);
        self::assertSame('notice', $byType->who);
        self::assertSame($c->make('notice.client'), $byType, 'the same singleton, not a second object');
    }

    public function test_two_named_beans_of_a_type_refuse_the_type(): void
    {
        $c = $this->container([NamedClientConfig::class, ReportClientConfig::class]);

        self::assertSame('notice', $c->make('notice.client')->who);
        self::assertSame('report', $c->make('report.client')->who);
        try {
            $c->make(ClientFixture::class);
            self::fail('a type with two named beans was resolved');
        } catch (\Throwable $e) {
            self::assertStringContainsString('2 beans of type ' . ClientFixture::class, $e->getMessage());
            self::assertStringContainsString('notice.client', $e->getMessage());
            self::assertStringContainsString('report.client', $e->getMessage());
        }
    }

    public function test_a_class_with_its_own_scope_attribute_keeps_its_type(): void
    {
        $c = Container::init();
        $c->singleton(ScopedClientFixture::class);
        $collector = new ConfigurationCollector($c);
        $collector->collect(NamedScopedConfig::class, new ReflectionClass(NamedScopedConfig::class));
        $collector->finish();

        self::assertSame('own', $c->make(ScopedClientFixture::class)->who);
        self::assertSame('named', $c->make('scoped.named')->who);
    }

    /** #[Inject('name')] on a #[Bean] method's parameter was ignored: it came by type. */
    #[DataProvider('bothOrders')]
    public function test_inject_by_name_works_in_a_bean_method(array $configs): void
    {
        $c = $this->container([...$configs, WrapperConfig::class]);

        self::assertSame('notice', $c->make(WrapperFixture::class)->client->who);
    }
}

final class ClientFixture
{
    public function __construct(public string $who)
    {
    }
}

#[Configuration]
final class UnnamedClientConfig
{
    #[Bean]
    public function client(): ClientFixture
    {
        return new ClientFixture('default');
    }
}

#[Configuration]
final class NamedClientConfig
{
    #[Bean(name: 'notice.client')]
    public function client(): ClientFixture
    {
        return new ClientFixture('notice');
    }
}

#[Configuration]
final class ReportClientConfig
{
    #[Bean(name: 'report.client')]
    public function client(): ClientFixture
    {
        return new ClientFixture('report');
    }
}

final class WrapperFixture
{
    public function __construct(public ClientFixture $client)
    {
    }
}

#[Configuration]
final class WrapperConfig
{
    #[Bean]
    public function wrapper(#[Inject('notice.client')] ClientFixture $client): WrapperFixture
    {
        return new WrapperFixture($client);
    }
}

final class NoticeConsumer
{
    public function __construct(#[Inject('notice.client')] public ClientFixture $client)
    {
    }
}

final class DefaultConsumer
{
    public function __construct(public ClientFixture $client)
    {
    }
}

#[Singleton]
final class ScopedClientFixture
{
    public string $who = 'own';
}

#[Configuration]
final class NamedScopedConfig
{
    #[Bean(name: 'scoped.named')]
    public function client(): ScopedClientFixture
    {
        $client = new ScopedClientFixture();
        $client->who = 'named';
        return $client;
    }
}
