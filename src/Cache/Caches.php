<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache;

use Flytachi\Winter\DI\Container;

/**
 * The caches an application declares, handed to every {@see CacheConfigurer}.
 *
 * ```
 * $caches
 *     ->table('api-token')                                              // a few values, ~1.6 MB
 *     ->table('products', rows: 10_000, valueSize: 4 * 1024, ttl: 300) // ~99 MB
 *     ->memory('stock', maxItems: 1_000, ttl: 30);
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

    private function ttl(string $name, ?int $ttl): ?int
    {
        if ($ttl !== null && $ttl < 0) {
            throw new \InvalidArgumentException("Cache '{$name}': ttl is in seconds and cannot be negative.");
        }

        return $ttl;
    }
}
