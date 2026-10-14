<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Concurrent\Async;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyCollector;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyFactory;

/**
 * Scanner collector that swaps classes carrying {@see Async} methods for their
 * generated proxies.
 *
 * Kept for code that wires the scanner by hand. The work is done by
 * {@see ProxyCollector}, which also covers the cache attributes — a class gets one
 * proxy whatever features it uses, so the application boot wires that one
 * directly.
 *
 * @deprecated Use {@see ProxyCollector} with a factory carrying an {@see AsyncInterceptor}.
 */
final class AsyncCollector implements CollectorInterface
{
    private readonly ProxyCollector $collector;

    /**
     * @param Container $container Container whose bindings are rewritten.
     * @param ProxyFactory|null $factory Source of the proxies; null builds an async-only one.
     * @param string|null $cacheFile Where the discovered list is stored; null keeps discovery on every boot.
     */
    public function __construct(Container $container, ?ProxyFactory $factory = null, ?string $cacheFile = null)
    {
        $this->collector = new ProxyCollector(
            $container,
            $factory ?? ProxyFactory::forKernel([new AsyncInterceptor()]),
            $cacheFile,
        );
    }

    public function collect(string $class, \ReflectionClass $ref): void
    {
        $this->collector->collect($class, $ref);
    }

    /**
     * Persists the discovered list so later boots skip the reflection pass.
     */
    public function flush(): void
    {
        $this->collector->flush();
    }

    /**
     * Returns every substitution made during the scan.
     *
     * @return array<class-string, class-string> Original class mapped to its proxy.
     */
    public function proxied(): array
    {
        return $this->collector->proxied();
    }
}
