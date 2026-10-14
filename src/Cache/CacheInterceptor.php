<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache;

use Flytachi\Winter\Kernel\Cache\Attribute\CacheConfig;
use Flytachi\Winter\Kernel\Cache\Attribute\CacheEvict;
use Flytachi\Winter\Kernel\Cache\Attribute\Cacheable;
use Flytachi\Winter\Kernel\Cache\Attribute\CachePut;
use Flytachi\Winter\Kernel\Core\Proxy\MethodInterceptor;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyException;

/**
 * Renders methods carrying {@see Cacheable}, {@see CachePut} or {@see CacheEvict}.
 *
 * Everything that can be decided from the declaration is decided here, while the
 * proxy is generated: which cache, which lifetime, how the key is built. What is
 * left for runtime is the lookup itself, in {@see CacheSupport}.
 *
 * The statements an override runs, in order: evictions marked `beforeInvocation`,
 * the call (through the cache for `#[Cacheable]`), the `#[CachePut]` write, the
 * remaining evictions. An exception from the body skips everything after it.
 *
 * @internal
 */
final class CacheInterceptor implements MethodInterceptor
{
    private const string SUPPORT = '\\Flytachi\\Winter\\Kernel\\Cache\\CacheSupport';

    /** Return types whose values cannot be kept and handed back later. */
    private const array UNSTORABLE = ['void', 'never', 'callable', 'iterable', 'closure', 'generator'];

    public function name(): string
    {
        return 'cache';
    }

    public function label(): string
    {
        return '#[Cacheable]/#[CachePut]/#[CacheEvict]';
    }

    public function intercepts(\ReflectionMethod $method): bool
    {
        return $method->getAttributes(Cacheable::class) !== []
            || $method->getAttributes(CachePut::class) !== []
            || $method->getAttributes(CacheEvict::class) !== [];
    }

    public function body(\ReflectionMethod $method, \ReflectionClass $class, string $call): string
    {
        $subject = $class->getName() . '::' . $method->getName() . '()';
        $config = self::classConfig($class, $method);

        $cacheable = ($method->getAttributes(Cacheable::class)[0] ?? null)?->newInstance();
        $put = ($method->getAttributes(CachePut::class)[0] ?? null)?->newInstance();
        $evicts = array_map(
            static fn(\ReflectionAttribute $a): CacheEvict => $a->newInstance(),
            $method->getAttributes(CacheEvict::class)
        );

        if ($cacheable !== null && $put !== null) {
            throw CacheException::of(
                $subject,
                'the method carries both #[Cacheable] and #[CachePut]',
                'Keep #[Cacheable] to skip the body on a hit, or #[CachePut] to always run it and refresh the entry.'
            );
        }

        $returns = self::returnsValue($method);
        if ($cacheable !== null || $put !== null) {
            $this->assertStorable($method, $subject, $cacheable !== null ? '#[Cacheable]' : '#[CachePut]');
        }

        $lines = [];
        foreach ($evicts as $evict) {
            if ($evict->beforeInvocation) {
                $lines[] = $this->evict($evict, $method, $subject, $config, false);
            }
        }

        $value = $call;
        if ($cacheable !== null) {
            $value = sprintf(
                '%s::cacheable(%s, %s, %s, %s, %s, fn() => %s)',
                self::SUPPORT,
                var_export(self::cacheName($cacheable->cache, $config, $subject, '#[Cacheable]'), true),
                KeyCompiler::compile($cacheable->key, $method, $subject, '#[Cacheable]', false),
                self::ttl($cacheable->ttl, $config, $subject),
                var_export($cacheable->sync, true),
                var_export($cacheable->cacheNull, true),
                $call
            );
        }

        $after = [];
        if ($put !== null) {
            $after[] = sprintf(
                '%s::put(%s, %s, %s, %s, %s);',
                self::SUPPORT,
                var_export(self::cacheName($put->cache, $config, $subject, '#[CachePut]'), true),
                KeyCompiler::compile($put->key, $method, $subject, '#[CachePut]', true),
                self::ttl($put->ttl, $config, $subject),
                var_export($put->cacheNull, true),
                KeyCompiler::RESULT_VARIABLE
            );
        }
        foreach ($evicts as $evict) {
            if (!$evict->beforeInvocation) {
                $after[] = $this->evict($evict, $method, $subject, $config, $returns);
            }
        }

        if (!$returns) {
            $lines[] = $value . ';';
            array_push($lines, ...$after);
        } elseif ($after === []) {
            $lines[] = 'return ' . $value . ';';
        } else {
            $lines[] = KeyCompiler::RESULT_VARIABLE . ' = ' . $value . ';';
            array_push($lines, ...$after);
            $lines[] = 'return ' . KeyCompiler::RESULT_VARIABLE . ';';
        }

        return implode("\n", $lines);
    }

    public function error(string $subject, string $problem, string $remedy): ProxyException
    {
        return CacheException::of($subject, $problem, $remedy);
    }

    /**
     * Lists the caches a class uses, so the boot can check they are declared.
     *
     * @param \ReflectionClass $class Proxied class.
     * @return list<array{string, string}> Method name and cache name, one pair per attribute.
     * @throws CacheException If an attribute names no cache and the class sets no default.
     */
    public static function usages(\ReflectionClass $class): array
    {
        $usages = [];
        foreach ($class->getMethods() as $method) {
            $subject = $class->getName() . '::' . $method->getName() . '()';
            $config = self::classConfig($class, $method);

            foreach ([Cacheable::class, CachePut::class, CacheEvict::class] as $type) {
                foreach ($method->getAttributes($type) as $attribute) {
                    $label = '#[' . new \ReflectionClass($type)->getShortName() . ']';
                    $cache = $attribute->newInstance()->cache;
                    $usages[] = [$subject, self::cacheName($cache, $config, $subject, $label)];
                }
            }
        }

        return $usages;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function evict(
        CacheEvict $evict,
        \ReflectionMethod $method,
        string $subject,
        ?CacheConfig $config,
        bool $resultAllowed,
    ): string {
        $cache = var_export(self::cacheName($evict->cache, $config, $subject, '#[CacheEvict]'), true);

        if ($evict->allEntries) {
            if ($evict->key !== null) {
                throw CacheException::of(
                    $subject,
                    '#[CacheEvict] has both a key and allEntries: true',
                    'Drop the key to empty the whole cache, or allEntries to remove one entry.'
                );
            }

            return sprintf('%s::evict(%s, null);', self::SUPPORT, $cache);
        }

        return sprintf(
            '%s::evict(%s, %s);',
            self::SUPPORT,
            $cache,
            KeyCompiler::compile($evict->key, $method, $subject, '#[CacheEvict]', $resultAllowed)
        );
    }

    /**
     * @throws CacheException If the result cannot be stored or the call cannot be replayed.
     */
    private function assertStorable(\ReflectionMethod $method, string $subject, string $attribute): void
    {
        $type = $method->getReturnType();
        $members = match (true) {
            $type instanceof \ReflectionUnionType => $type->getTypes(),
            $type === null => [],
            default => [$type],
        };

        foreach ($members as $member) {
            $name = $member instanceof \ReflectionNamedType ? strtolower(ltrim($member->getName(), '\\')) : '';
            if (in_array($name, self::UNSTORABLE, true)) {
                throw CacheException::of(
                    $subject,
                    $attribute . ' needs a value to store, but the method returns ' . $type,
                    'Cache a method that returns the data itself; a generator or a closure cannot be kept '
                    . 'and replayed later.'
                );
            }
        }

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isPassedByReference()) {
                throw CacheException::of(
                    $subject,
                    'parameter $' . $parameter->getName() . ' is passed by reference',
                    'On a cache hit the body does not run, so it could not write to it. Return the value instead.'
                );
            }
        }
    }

    /**
     * Whether the method hands back a value — not void, not never.
     */
    private static function returnsValue(\ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();

        return !($type instanceof \ReflectionNamedType
            && in_array(strtolower($type->getName()), ['void', 'never'], true));
    }

    private static function classConfig(\ReflectionClass $class, \ReflectionMethod $method): ?CacheConfig
    {
        foreach ([$class, $method->getDeclaringClass()] as $owner) {
            $attributes = $owner->getAttributes(CacheConfig::class);
            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        }

        return null;
    }

    /**
     * @throws CacheException If neither the attribute nor the class names a cache.
     */
    private static function cacheName(?string $cache, ?CacheConfig $config, string $subject, string $attribute): string
    {
        $name = $cache ?? $config?->cache;
        if ($name === null || $name === '') {
            throw CacheException::of(
                $subject,
                $attribute . ' names no cache',
                "Name it on the attribute, {$attribute}('products'), or for the whole class with "
                . "#[CacheConfig('products')]."
            );
        }

        return $name;
    }

    /**
     * @return string PHP literal: the lifetime in seconds, or null for the cache's own.
     * @throws CacheException If the lifetime is negative.
     */
    private static function ttl(?int $ttl, ?CacheConfig $config, string $subject): string
    {
        $ttl ??= $config?->ttl;
        if ($ttl !== null && $ttl < 0) {
            throw CacheException::of($subject, 'ttl is negative', 'Give it in seconds; 0 never expires.');
        }

        return var_export($ttl, true);
    }
}
