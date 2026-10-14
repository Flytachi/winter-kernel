<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Cache;

use Flytachi\Winter\Kernel\Cache\Attribute\CacheConfig;
use Flytachi\Winter\Kernel\Cache\Attribute\CacheEvict;
use Flytachi\Winter\Kernel\Cache\Attribute\Cacheable;
use Flytachi\Winter\Kernel\Cache\Attribute\CachePut;
use Flytachi\Winter\Kernel\Cache\CacheException;
use Flytachi\Winter\Kernel\Cache\CacheInterceptor;
use Flytachi\Winter\Kernel\Concurrent\Async\Async;
use Flytachi\Winter\Kernel\Concurrent\Async\AsyncInterceptor;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyException;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The cache attributes' contract, enforced at proxy generation.
 *
 * Every rule here guards against a cache that would look fine and do the wrong
 * thing: a key naming a parameter that does not exist matches nothing, so the
 * cache never hits — and an eviction with such a key never evicts, so stale data
 * is served. Each fixture breaks exactly one rule.
 */
final class CacheContractTest extends TestCase
{
    private static function generate(string $class, bool $withAsync = false): string
    {
        $interceptors = $withAsync ? [new AsyncInterceptor(), new CacheInterceptor()] : [new CacheInterceptor()];

        return ProxyGenerator::generate(new ReflectionClass($class), $interceptors);
    }

    // ── Accepted ───────────────────────────────────────────────────────────────

    public function test_a_valid_service_is_generated_and_lints(): void
    {
        $source = self::generate(ValidCacheService::class);

        $file = tempnam(sys_get_temp_dir(), 'wk_cache_proxy');
        file_put_contents($file, $source);
        try {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        } finally {
            @unlink($file);
        }

        self::assertSame(0, $code, implode("\n", $output));
    }

    public function test_the_key_template_is_compiled_into_the_proxy(): void
    {
        $source = self::generate(ValidCacheService::class);

        self::assertStringContainsString("'product:' . \\Flytachi\\Winter\\Kernel\\Cache\\CacheSupport::part(\$id)", $source);
        self::assertStringContainsString('CacheSupport::part($filter?->region)', $source, 'a property is read null-safe');
        self::assertStringContainsString('CacheSupport::part($__winterResult?->id)', $source, '{result} reads the returned value');
    }

    public function test_the_class_defaults_apply_and_the_method_wins(): void
    {
        $source = self::generate(ValidCacheService::class);

        self::assertStringContainsString("cacheable('products', 'product:' . \\Flytachi\\Winter\\Kernel\\Cache\\CacheSupport::part(\$id), 0,", $source);
        self::assertStringContainsString("cacheable('lists', 'all', 60,", $source);
    }

    public function test_evictions_run_in_their_place_around_the_body(): void
    {
        $source = self::generate(ValidCacheService::class);
        $delete = substr($source, strpos($source, 'function delete('));
        $delete = substr($delete, 0, strpos($delete, "\n    }") ?: null);

        $before = strpos($delete, "evict('lists', null)");
        $call   = strpos($delete, 'parent::delete($id)');
        $after  = strpos($delete, "evict('products'");

        self::assertNotFalse($before);
        self::assertTrue($before < $call && $call < $after, $delete);
    }

    public function test_async_and_cache_share_one_proxy(): void
    {
        $source = self::generate(MixedService::class, withAsync: true);

        self::assertStringContainsString('AsyncSupport::execute(', $source);
        self::assertStringContainsString('CacheSupport::cacheable(', $source);
        self::assertStringContainsString(ProxyGenerator::MARKER . 'async, cache', $source);
    }

    public function test_usages_skip_methods_of_other_features(): void
    {
        self::assertSame(
            [[MixedService::class . '::count()', 'products']],
            CacheInterceptor::usages(new ReflectionClass(MixedService::class)),
        );
    }

    public function test_usages_list_every_cache_a_class_names(): void
    {
        $caches = array_column(CacheInterceptor::usages(new ReflectionClass(ValidCacheService::class)), 1);
        sort($caches);

        self::assertSame(['lists', 'lists', 'lists', 'products', 'products', 'products', 'products'], $caches);
    }

    // ── Rejected ───────────────────────────────────────────────────────────────

    /** @return array<string, array{class-string, string}> */
    public static function violations(): array
    {
        return [
            'no cache name'          => [NoCacheNameService::class, 'names no cache'],
            'unknown parameter'      => [UnknownParameterService::class, 'no parameter $idd'],
            'unbalanced brace'       => [UnbalancedBraceService::class, 'unbalanced brace'],
            'empty key'              => [EmptyKeyService::class, 'empty key'],
            'result in cacheable'    => [ResultInCacheableService::class, '{result} is available only'],
            'object without key'     => [ObjectWithoutKeyService::class, 'cannot be part of one'],
            'object placeholder'     => [ObjectPlaceholderService::class, 'cannot be part of a key'],
            'missing property'       => [MissingPropertyService::class, 'no public property $sku'],
            'void cacheable'         => [VoidCacheableService::class, 'needs a value to store'],
            'generator'              => [GeneratorService::class, 'needs a value to store'],
            'by reference'           => [ByReferenceService::class, 'passed by reference'],
            'cacheable and put'      => [CacheableAndPutService::class, 'both #[Cacheable] and #[CachePut]'],
            'key with all entries'   => [KeyAndAllEntriesService::class, 'both a key and allEntries'],
            'negative ttl'           => [NegativeTtlService::class, 'ttl is negative'],
            'result after void'      => [ResultAfterVoidService::class, '{result} is available only'],
            'final class'            => [FinalCacheService::class, 'final'],
            'private method'         => [PrivateCacheService::class, 'private'],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('violations')]
    public function test_a_violation_is_reported_at_generation(string $class, string $needle): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage($needle);

        self::generate($class);
    }

    public function test_async_and_cache_on_one_method_are_refused(): void
    {
        $this->expectException(ProxyException::class);
        $this->expectExceptionMessage('carries both #[Async] and');

        self::generate(AsyncAndCacheService::class, withAsync: true);
    }

    public function test_an_eviction_may_sit_on_a_void_method_with_a_reference_parameter(): void
    {
        $source = self::generate(EvictByReferenceService::class);

        self::assertStringContainsString('function reset(array &$rows): void', $source);
    }
}

// ── Fixtures ────────────────────────────────────────────────────────────────────

final class CacheFilter
{
    public string $region = '';
}

final class CachedProduct
{
    public int $id = 0;
}

enum CacheRegion: string
{
    case North = 'n';
}

#[CacheConfig('products', ttl: 60)]
class ValidCacheService
{
    #[Cacheable(key: 'product:{id}', ttl: 0)]
    public function find(int $id): ?CachedProduct
    {
        return null;
    }

    #[Cacheable('lists', key: 'all')]
    public function all(): array
    {
        return [];
    }

    #[Cacheable('lists', key: '{filter.region}:{page}')]
    public function search(CacheFilter $filter, int $page = 1, ?CacheRegion $region = null): array
    {
        return [];
    }

    #[CachePut(key: '{result.id}')]
    public function save(CachedProduct $product): CachedProduct
    {
        return $product;
    }

    #[CacheEvict(key: '{id}')]
    #[CacheEvict('lists', allEntries: true, beforeInvocation: true)]
    public function delete(int $id): void
    {
    }

    #[Cacheable(key: '{id}')]
    protected function inner(int $id, string ...$tags): string
    {
        return '';
    }
}

class MixedService
{
    #[Async]
    public function fire(): void
    {
    }

    #[Cacheable('products')]
    public function count(): int
    {
        return 0;
    }
}

class NoCacheNameService
{
    #[Cacheable]
    public function find(int $id): int
    {
        return $id;
    }
}

class UnknownParameterService
{
    #[Cacheable('products', key: '{idd}')]
    public function find(int $id): int
    {
        return $id;
    }
}

class UnbalancedBraceService
{
    #[Cacheable('products', key: '{id')]
    public function find(int $id): int
    {
        return $id;
    }
}

class EmptyKeyService
{
    #[Cacheable('products', key: '')]
    public function find(int $id): int
    {
        return $id;
    }
}

class ResultInCacheableService
{
    #[Cacheable('products', key: '{result}')]
    public function find(int $id): int
    {
        return $id;
    }
}

class ObjectWithoutKeyService
{
    #[Cacheable('products')]
    public function find(CachedProduct $product): int
    {
        return 1;
    }
}

class ObjectPlaceholderService
{
    #[Cacheable('products', key: '{product}')]
    public function find(CachedProduct $product): int
    {
        return 1;
    }
}

class MissingPropertyService
{
    #[Cacheable('products', key: '{product.sku}')]
    public function find(CachedProduct $product): int
    {
        return 1;
    }
}

class VoidCacheableService
{
    #[Cacheable('products')]
    public function touch(int $id): void
    {
    }
}

class GeneratorService
{
    #[Cacheable('products')]
    public function rows(int $id): \Generator
    {
        yield $id;
    }
}

class ByReferenceService
{
    #[Cacheable('products', key: 'x')]
    public function fill(array &$rows): int
    {
        return 1;
    }
}

class CacheableAndPutService
{
    #[Cacheable('products')]
    #[CachePut('products')]
    public function find(int $id): int
    {
        return $id;
    }
}

class KeyAndAllEntriesService
{
    #[CacheEvict('products', key: '{id}', allEntries: true)]
    public function drop(int $id): void
    {
    }
}

class NegativeTtlService
{
    #[Cacheable('products', ttl: -1)]
    public function find(int $id): int
    {
        return $id;
    }
}

class ResultAfterVoidService
{
    #[CacheEvict('products', key: '{result}')]
    public function drop(int $id): void
    {
    }
}

final class FinalCacheService
{
    #[Cacheable('products')]
    public function find(int $id): int
    {
        return $id;
    }
}

class PrivateCacheService
{
    public function outer(int $id): int
    {
        return $this->find($id);
    }

    #[Cacheable('products')]
    private function find(int $id): int
    {
        return $id;
    }
}

class AsyncAndCacheService
{
    #[Async]
    #[CacheEvict('products', allEntries: true)]
    public function fire(): void
    {
    }
}

class EvictByReferenceService
{
    #[CacheEvict('products', allEntries: true)]
    public function reset(array &$rows): void
    {
    }
}
