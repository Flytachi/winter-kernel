<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Cache;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\Cache\Attribute\CacheEvict;
use Flytachi\Winter\Kernel\Cache\Attribute\Cacheable;
use Flytachi\Winter\Kernel\Cache\CacheConfigurer;
use Flytachi\Winter\Kernel\Cache\CacheException;
use Flytachi\Winter\Kernel\Cache\Caches;
use Flytachi\Winter\Kernel\Cache\CacheType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Declaring caches, and the boot check that every attribute names one of them.
 */
final class CachesTest extends TestCase
{
    public function test_both_kinds_are_declared(): void
    {
        $definitions = new Caches()
            ->table('products', rows: 1000, valueSize: 2048, ttl: 300)
            ->memory('stock', maxItems: 50)
            ->definitions();

        self::assertSame(CacheType::Table, $definitions['products']->type);
        self::assertSame(1000, $definitions['products']->size);
        self::assertSame(2048, $definitions['products']->valueSize);
        self::assertSame(300, $definitions['products']->ttl);
        self::assertSame(CacheType::Memory, $definitions['stock']->type);
        self::assertNull($definitions['stock']->ttl);
    }

    public function test_a_table_without_sizes_is_sized_for_a_handful_of_values(): void
    {
        $definition = new Caches()->table('api-token')->definitions()['api-token'];

        self::assertSame(64, $definition->size);
        self::assertSame(16 * 1024, $definition->valueSize);
        self::assertNull($definition->ttl);
        // Measured at +1.6 MB of RSS; cheap enough to be the default.
        self::assertLessThan(2 * 1024 * 1024, $definition->reservedBytes());
    }

    public function test_the_default_value_size_holds_a_list_of_entities(): void
    {
        $entities = array_map(
            static fn(int $i): array => [
                'id' => $i,
                'name' => 'Wireless mouse',
                'sku' => 'WM-' . $i,
                'price' => 19.9,
                'description' => str_repeat('Lorem ipsum ', 10),
                'tags' => ['office', 'usb'],
                'created_at' => '2026-10-14 10:00:00',
            ],
            range(1, 45),
        );

        self::assertLessThanOrEqual(Caches::DEFAULT_VALUE_SIZE, strlen(serialize($entities)));
    }

    public function test_a_name_is_declared_once(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('declared twice');

        new Caches()->memory('products')->table('products', rows: 10, valueSize: 64);
    }

    /** @return array<string, array{callable(Caches): mixed, string}> */
    public static function invalid(): array
    {
        return [
            'no rows'          => [static fn(Caches $c) => $c->table('t', rows: 0, valueSize: 64), 'rows'],
            'tiny value'       => [static fn(Caches $c) => $c->table('t', rows: 10, valueSize: 4), 'in bytes'],
            'no items'         => [static fn(Caches $c) => $c->memory('m', maxItems: 0), 'maxItems'],
            'negative ttl'     => [static fn(Caches $c) => $c->memory('m', ttl: -5), 'cannot be negative'],
            'empty name'       => [static fn(Caches $c) => $c->memory(''), 'needs a name'],
        ];
    }

    /** @param callable(Caches): mixed $declare */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function test_numbers_out_of_range_are_refused(callable $declare, string $needle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($needle);

        $declare(new Caches());
    }

    public function test_declare_runs_the_configurers(): void
    {
        $definitions = Caches::declare(
            Container::init(),
            [new ReflectionClass(DeclaringConfigurer::class)],
            [UsesDeclaredCaches::class],
        );

        self::assertSame(['products', 'lists'], array_keys($definitions));
    }

    public function test_an_undeclared_cache_stops_the_boot(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("undeclared cache 'prodcuts' used by " . UsesMisspeltCache::class . '::find()');

        Caches::declare(Container::init(), [new ReflectionClass(DeclaringConfigurer::class)], [UsesMisspeltCache::class]);
    }

    public function test_a_misspelt_eviction_is_caught_as_well(): void
    {
        // The dangerous one: a #[Cacheable] typo costs hits, an eviction typo serves
        // stale data forever.
        $this->expectExceptionMessage("'list' used by " . UsesMisspeltCache::class . '::reset()');

        Caches::declare(Container::init(), [new ReflectionClass(DeclaringConfigurer::class)], [UsesMisspeltCache::class]);
    }

    public function test_without_any_configurer_the_message_says_what_to_write(): void
    {
        $this->expectExceptionMessage('Declare the caches in a class implementing ' . CacheConfigurer::class);

        Caches::declare(Container::init(), [], [UsesDeclaredCaches::class]);
    }
}

// ── Fixtures ────────────────────────────────────────────────────────────────────

final class DeclaringConfigurer implements CacheConfigurer
{
    public function configureCaches(Caches $caches): void
    {
        $caches->table('products', rows: 100, valueSize: 256)->memory('lists');
    }
}

class UsesDeclaredCaches
{
    #[Cacheable('products', key: '{id}')]
    public function find(int $id): int
    {
        return $id;
    }

    #[CacheEvict('lists', allEntries: true)]
    public function reset(): void
    {
    }
}

class UsesMisspeltCache
{
    #[Cacheable('prodcuts', key: '{id}')]
    public function find(int $id): int
    {
        return $id;
    }

    #[CacheEvict('list', allEntries: true)]
    public function reset(): void
    {
    }
}
