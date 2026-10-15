<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;

/**
 * The caches an application declares, handed to every {@see CacheConfigurer}.
 *
 * ```
 * $caches
 *     ->table('settings')                                               // a few values, ~1.6 MB
 *     ->table('products', rows: 10_000, valueSize: 4 * 1024, ttl: 300) // ~99 MB
 *     ->memory('stock', maxItems: 1_000, ttl: 30)                      // per worker
 *     ->file('api-token', ttl: 3300)                                   // survives restarts, seen by `call`
 *     ->redis('sessions', MainRedisConfig::class, ttl: 600);           // shared by every instance
 * ```
 *
 * Every name the cache attributes use has to be declared here; an undeclared one
 * stops the boot.
 */
final class Caches
{
    /** Smallest value column worth declaring; anything less holds hardly any value. */
    private const int MIN_VALUE_SIZE = 16;

    /** Entries of a table declared without sizes: room for a handful of values. */
    public const int DEFAULT_ROWS = 64;

    /** Largest value of a table declared without sizes, in bytes. */
    public const int DEFAULT_VALUE_SIZE = 16 * 1024;

    /** The winter-redis endpoint contract, by name: the package is optional. */
    private const string REDIS_CONFIG = 'Flytachi\\Winter\\Redis\\Config\\Common\\RedisConfigInterface';

    /** @var array<string, CacheDefinition> */
    private array $definitions = [];

    /**
     * Declares a cache kept in a `Swoole\Table`, shared by every worker and by the
     * processes started beside the server.
     *
     * The memory is reserved when the application starts — roughly
     * `rows × 1.5 × (valueSize + 120)` bytes, with rows rounded up to a power of
     * two — so both numbers are a promise, not a hint. A value that serializes to
     * more than `valueSize` bytes, or one arriving while all rows are taken, is not
     * cached: the method still returns it, and a warning says so.
     *
     * The defaults are sized for the common case of a handful of values — an API
     * token, settings loaded from the database, a short reference list: 64 entries
     * of up to 16 KB each, about 1.6 MB. That holds a token response (~1 KB), one
     * entity (~350 bytes) or a list of some 45 of them. Caching thousands of entities
     * needs both numbers set explicitly.
     *
     * @param string $name Name the attributes refer to.
     * @param int $rows Entries the table holds.
     * @param int $valueSize Largest serialized value, in bytes.
     * @param int|null $ttl Default lifetime in seconds; null or 0 never expires.
     * @return $this
     * @throws CacheException If the name is already declared.
     * @throws \InvalidArgumentException If a number is out of range.
     */
    public function table(
        string $name,
        int $rows = self::DEFAULT_ROWS,
        int $valueSize = self::DEFAULT_VALUE_SIZE,
        ?int $ttl = null,
    ): self {
        if ($rows < 1) {
            throw new \InvalidArgumentException("Cache '{$name}': rows must be at least 1, {$rows} given.");
        }
        if ($valueSize < self::MIN_VALUE_SIZE) {
            throw new \InvalidArgumentException(
                "Cache '{$name}': valueSize is in bytes and must be at least " . self::MIN_VALUE_SIZE
                . ", {$valueSize} given."
            );
        }

        return $this->add(new CacheDefinition($name, CacheType::Table, $rows, $valueSize, $this->ttl($name, $ttl)));
    }

    /**
     * Declares a cache kept in each worker's own memory.
     *
     * Nothing is reserved up front and any value fits, but every worker fills its
     * own copy. Past `maxItems` the entry read least recently is dropped.
     *
     * @param string $name Name the attributes refer to.
     * @param int $maxItems Entries kept per worker.
     * @param int|null $ttl Default lifetime in seconds; null or 0 never expires.
     * @return $this
     * @throws CacheException If the name is already declared.
     * @throws \InvalidArgumentException If a number is out of range.
     */
    public function memory(string $name, int $maxItems = 1000, ?int $ttl = null): self
    {
        if ($maxItems < 1) {
            throw new \InvalidArgumentException("Cache '{$name}': maxItems must be at least 1, {$maxItems} given.");
        }

        return $this->add(new CacheDefinition($name, CacheType::Memory, $maxItems, null, $this->ttl($name, $ttl)));
    }

    /**
     * Declares a cache kept in files under `storage/cache/winter-cache/<name>`.
     *
     * Every worker and every process reads the same files, the entries survive a
     * restart, and a console command can list and clear them (`call cache`). A read
     * is a file read — about 17 µs, slower than a table but far from a database
     * query; a write about 150 µs. Under FPM, where a process lives for one request,
     * this is the store that still caches.
     *
     * Entries outlive a deploy. If the cached classes changed shape, give the cache a
     * new `version`: older entries are then simply not found.
     *
     * @param string $name Name the attributes refer to; also the folder name.
     * @param int|null $ttl Default lifetime in seconds; null or 0 never expires.
     * @param string|null $version Generation of the entries; a subfolder when set.
     * @return $this
     * @throws CacheException If the name is already declared.
     * @throws \InvalidArgumentException If the name or version cannot be a folder.
     */
    public function file(string $name, ?int $ttl = null, ?string $version = null): self
    {
        $this->assertPathSafe($name, 'name', $name);
        if ($version !== null) {
            $this->assertPathSafe($name, 'version', $version);
        }

        return $this->add(new CacheDefinition(
            $name,
            CacheType::File,
            0,
            null,
            $this->ttl($name, $ttl),
            version: $version,
        ));
    }

    /**
     * Declares a cache kept in Redis, through the pooled connection of winter-redis.
     *
     * The one store shared by every instance of the application: an eviction on one
     * server is seen by all of them. Keys carry the prefix `cache:<name>:` (or the
     * given one) and the version after it. If Redis cannot be reached, a read counts
     * as a miss and a write is skipped — the method still answers, and the log says
     * the cache is down.
     *
     * @param string $name Name the attributes refer to.
     * @param string $config Endpoint class implementing the winter-redis RedisConfigInterface.
     * @param int|null $ttl Default lifetime in seconds; null or 0 never expires.
     * @param string|null $prefix Key prefix; null uses `cache:<name>:`.
     * @param string|null $version Generation of the entries; part of the prefix when set.
     * @return $this
     * @throws CacheException If the name is already declared or winter-redis is missing.
     * @throws \InvalidArgumentException If the endpoint class is not a Redis config.
     */
    public function redis(
        string $name,
        string $config,
        ?int $ttl = null,
        ?string $prefix = null,
        ?string $version = null,
    ): self {
        if (!DepSupport::has(Dep::Redis)) {
            throw CacheException::of(
                "Cache '{$name}'",
                'a redis cache needs ' . Dep::Redis->package() . ', which is not installed',
                'Add it with: composer require ' . Dep::Redis->package()
            );
        }

        if (!is_subclass_of($config, self::REDIS_CONFIG)) {
            throw new \InvalidArgumentException(
                "Cache '{$name}': {$config} is not a Redis endpoint; pass a class implementing "
                . self::REDIS_CONFIG . '.'
            );
        }

        if ($prefix === '') {
            throw new \InvalidArgumentException(
                "Cache '{$name}': an empty prefix would let clearing the cache empty the whole database."
            );
        }

        if ($version !== null) {
            $this->assertPathSafe($name, 'version', $version);
        }

        return $this->add(new CacheDefinition(
            $name,
            CacheType::Redis,
            0,
            null,
            $this->ttl($name, $ttl),
            redisConfig: $config,
            prefix: $prefix,
            version: $version,
        ));
    }

    /**
     * Runs every configurer and checks that each cache attribute of the proxied
     * classes names a declared cache.
     *
     * @internal Shared by the application boot and `call di build`.
     * @param Container $container Container the configurers are resolved from.
     * @param list<\ReflectionClass> $configurers Classes implementing CacheConfigurer.
     * @param iterable<class-string> $proxied Classes swapped for proxies.
     * @return array<string, CacheDefinition> Declared caches, keyed by name.
     * @throws CacheException If a cache is used but not declared.
     */
    public static function declare(Container $container, array $configurers, iterable $proxied): array
    {
        $caches = new self();
        foreach ($configurers as $ref) {
            /** @var CacheConfigurer $configurer */
            $configurer = $container->make($ref->getName());
            $configurer->configureCaches($caches);
        }
        $definitions = $caches->definitions();

        $missing = [];
        foreach ($proxied as $class) {
            foreach (CacheInterceptor::usages(new \ReflectionClass($class)) as [$subject, $cache]) {
                if (!isset($definitions[$cache])) {
                    $missing[] = "'{$cache}' used by {$subject}";
                }
            }
        }

        if ($missing !== []) {
            throw CacheException::of(
                'Caching',
                'undeclared cache ' . implode(', ', array_unique($missing)),
                $configurers === []
                    ? 'Declare the caches in a class implementing ' . CacheConfigurer::class . '.'
                    : 'Declare it in your CacheConfigurer, or fix the name on the attribute.'
            );
        }

        return $definitions;
    }

    /**
     * Returns every declared cache.
     *
     * @return array<string, CacheDefinition> Keyed by name.
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * @return $this
     */
    private function add(CacheDefinition $definition): self
    {
        if ($definition->name === '') {
            throw new \InvalidArgumentException('A cache needs a name.');
        }

        if (isset($this->definitions[$definition->name])) {
            throw CacheException::of(
                "Cache '{$definition->name}'",
                'it is declared twice',
                'Each name is declared once; check every CacheConfigurer in the application and its packages.'
            );
        }

        $this->definitions[$definition->name] = $definition;

        return $this;
    }

    /**
     * A name or version that becomes a folder or a key segment may not escape it.
     */
    private function assertPathSafe(string $name, string $what, string $value): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) !== 1) {
            throw new \InvalidArgumentException(
                "Cache '{$name}': the {$what} '{$value}' may hold only letters, digits, '.', '_' and '-', "
                . 'and must start with a letter or a digit.'
            );
        }
    }

    private function ttl(string $name, ?int $ttl): ?int
    {
        if ($ttl !== null && $ttl < 0) {
            throw new \InvalidArgumentException("Cache '{$name}': ttl is in seconds and cannot be negative.");
        }

        return $ttl;
    }
}
