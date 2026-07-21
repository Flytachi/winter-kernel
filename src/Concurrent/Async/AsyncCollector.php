<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Concurrent\Async;

use Flytachi\Winter\DI\Attribute\Request;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Attribute\Transient;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\K2\Concurrent\Async\Proxy\ProxyFactory;
use Flytachi\Winter\K2\Concurrent\Async\Proxy\ProxyGenerator;
use Flytachi\Winter\K2\Kernel;

/**
 * Scanner collector that swaps classes carrying {@see Async} methods for their
 * generated proxies.
 *
 * This is what makes the attribute self-sufficient: the developer annotates a
 * method and injects the service, and the container hands out the proxy
 * instead of the original. Nothing is registered by hand.
 *
 * The DI lifetime of the original is preserved — a `#[Singleton]` service stays
 * a singleton, only its concrete class changes. A service with no scope
 * attribute is bound so that auto-wiring resolves the proxy too.
 *
 * Its identity is preserved as well: the generated class implements
 * `ProxyInterface`, so property injection and `contextual()` factories still see
 * the original class name.
 *
 * ---
 * ### Ordering matters
 *
 * Register **after** `DICollector`. That collector rebinds a class to itself,
 * so running it later would undo the substitution:
 *
 * ```
 * Scanner::run(rootDir: Kernel::$pathRoot, cache: $cache)
 *     ->collect(new DICollector($container))
 *     ->collect(new AsyncCollector($container, ProxyFactory::forKernel()))
 *     ->execute();
 * ```
 *
 * @see Async
 * @see ProxyFactory
 */
final class AsyncCollector implements CollectorInterface
{
    /** @var array<class-string, class-string> Original class mapped to its proxy. */
    private array $proxied = [];

    /**
     * Names of the classes known to be annotated, or null while discovering.
     *
     * Finding them means reflecting every method of every class in the project —
     * roughly three times the cost of {@see \Flytachi\Winter\DI\Collector\DICollector}
     * itself, paid on every boot, which under FPM means every request. The answer
     * does not change between boots, so it is written next to the DI cache and
     * replayed as a plain lookup.
     *
     * @var array<class-string, true>|null
     */
    private ?array $known = null;

    /**
     * @param Container $container Container whose bindings are rewritten.
     * @param ProxyFactory $factory Source of the generated proxies.
     * @param string|null $cacheFile Where the discovered list is stored; null keeps discovery on every boot.
     */
    public function __construct(
        private readonly Container $container,
        private readonly ProxyFactory $factory,
        private readonly ?string $cacheFile = null
    ) {
        if ($cacheFile !== null && is_file($cacheFile)) {
            $cached = require $cacheFile;
            $this->known = is_array($cached) ? $cached : null;
        }
    }

    public function collect(string $class, \ReflectionClass $ref): void
    {
        // Generated proxies live under the project root as well and would
        // otherwise be scanned as ordinary classes.
        if (str_starts_with($class, ProxyGenerator::PROXY_NAMESPACE . '\\')) {
            return;
        }

        if ($this->known !== null) {
            if (isset($this->known[$class])) {
                $this->bind($class, $ref);
            }

            return;
        }

        if (ProxyGenerator::asyncMethods($ref) === []) {
            return;
        }

        $this->bind($class, $ref);
    }

    /**
     * Persists the discovered list so later boots skip the reflection pass.
     *
     * Call once, after the scan has finished. Writing an empty list matters as
     * much as writing a full one: a project without any `#[Async]` service must
     * not rediscover that fact on every boot.
     */
    public function flush(): void
    {
        if ($this->cacheFile === null || $this->known !== null) {
            return;
        }

        $known = array_fill_keys(array_keys($this->proxied), true);
        $export = var_export($known, true);
        $temporary = $this->cacheFile . '.' . getmypid() . '.tmp';

        Kernel::ensureDirectory(dirname($this->cacheFile));

        if (file_put_contents($temporary, "<?php\n\nreturn {$export};\n", LOCK_EX) !== false) {
            rename($temporary, $this->cacheFile);
        } else {
            @unlink($temporary);
        }
    }

    /**
     * Returns every substitution made during the scan.
     *
     * @return array<class-string, class-string> Original class mapped to its proxy.
     */
    public function proxied(): array
    {
        return $this->proxied;
    }

    /**
     * Generates the proxy if needed and points the container at it.
     *
     * @param class-string $class Original class.
     * @param \ReflectionClass $ref Reflection of the original.
     */
    private function bind(string $class, \ReflectionClass $ref): void
    {
        $proxy = $this->factory->proxyFor($ref);
        $this->rebind($class, $proxy, $ref);
        $this->proxied[$class] = $proxy;
    }

    /**
     * Points the container at the proxy while keeping the original lifetime.
     *
     * @param class-string $class Original class.
     * @param class-string $proxy Generated subclass.
     * @param \ReflectionClass $ref Reflection of the original.
     */
    private function rebind(string $class, string $proxy, \ReflectionClass $ref): void
    {
        match (true) {
            $ref->getAttributes(Singleton::class) !== [] => $this->container->singleton($class, $proxy),
            $ref->getAttributes(Request::class) !== [] => $this->container->request($class, $proxy),
            $ref->getAttributes(Transient::class) !== [] => $this->container->transient($class, $proxy),
            default => $this->container->bind($class, $proxy),
        };
    }
}
