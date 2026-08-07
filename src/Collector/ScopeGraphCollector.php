<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Collector;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\DI\Attribute\Request;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Contract\CollectorInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Records who depends on whom, so the boot can refuse a `#[Singleton]` that reaches a
 * `#[Request]` bean.
 *
 * A singleton is built once and its injected properties are filled at that moment — for
 * the lifetime of the worker. A request-scoped dependency resolved then belongs to
 * whichever request happened to be first, and every later request keeps seeing it. With
 * an authentication context in that position, every user after the first is served under
 * the first user's identity. Nothing throws, nothing is logged; the symptom reaches a
 * customer before it reaches a log line.
 *
 * The reach is transitive, which is the part that surprises: a singleton freezes its
 * whole dependency subtree, so `#[Singleton] → plain service → #[Request]` leaks exactly
 * as badly as a direct dependency. Verified on a live server, not reasoned about.
 *
 * Spring, where singleton is the *default* scope, either injects a scoped proxy or fails
 * to start. It never leaks silently. This collector buys the second half of that
 * guarantee: gather the graph during the one scan pass, then let
 * {@see assertNoFrozenRequestScope()} walk it.
 */
final class ScopeGraphCollector implements CollectorInterface
{
    /** @var array<class-string, true> Classes carrying `#[Singleton]`. */
    private array $singletons = [];

    /** @var array<class-string, true> Classes carrying `#[Request]`. */
    private array $requestScoped = [];

    /** @var array<class-string, list<array{property: string, type: class-string}>> */
    private array $edges = [];

    public function collect(string $class, ReflectionClass $ref): void
    {
        if ($ref->getAttributes(Singleton::class) !== []) {
            $this->singletons[$class] = true;
        }
        if ($ref->getAttributes(Request::class) !== []) {
            $this->requestScoped[$class] = true;
        }

        foreach ($ref->getProperties() as $property) {
            if ($property->getAttributes(Autowired::class) === [] && $property->getAttributes(Inject::class) === []) {
                continue;
            }

            $type = $this->targetOf($property->getAttributes(Inject::class), $property->getType());
            if ($type !== null) {
                $this->edges[$class][] = ['property' => $property->getName(), 'type' => $type];
            }
        }
    }

    /**
     * Fails the boot when a singleton can reach a request-scoped bean.
     *
     * The message names the whole path rather than the endpoints, because the middle of
     * the chain is where the mistake usually hides — each link looks correct on its own.
     *
     * @throws ScopeConflictException
     */
    public function assertNoFrozenRequestScope(): void
    {
        $conflicts = [];

        foreach (array_keys($this->singletons) as $singleton) {
            $path = $this->pathToRequestScope($singleton, [$singleton => true]);
            if ($path !== null) {
                $conflicts[] = $singleton . ' → ' . implode(' → ', $path);
            }
        }

        if ($conflicts !== []) {
            throw ScopeConflictException::of($conflicts);
        }
    }

    /**
     * Depth-first walk to the first request-scoped class reachable from $class.
     *
     * @param class-string $class
     * @param array<class-string, true> $seen Guards against a dependency cycle.
     * @return list<string>|null The remaining hops, or null when nothing is reachable.
     */
    private function pathToRequestScope(string $class, array $seen): ?array
    {
        foreach ($this->edges[$class] ?? [] as $edge) {
            $target = $edge['type'];
            $hop = "\${$edge['property']}: {$target}";

            if (isset($this->requestScoped[$target])) {
                return [$hop];
            }
            if (isset($seen[$target])) {
                continue;
            }

            $deeper = $this->pathToRequestScope($target, $seen + [$target => true]);
            if ($deeper !== null) {
                return [$hop, ...$deeper];
            }
        }

        return null;
    }

    /**
     * The class a property resolves to: an explicit `#[Inject(Foo::class)]` wins over the
     * declared type, mirroring how the container itself decides.
     *
     * @param list<\ReflectionAttribute> $injectAttributes
     * @return class-string|null Null for scalars, unions and untyped properties — the
     *                           container cannot resolve those either.
     */
    private function targetOf(array $injectAttributes, ?\ReflectionType $declared): ?string
    {
        if ($injectAttributes !== []) {
            $id = $injectAttributes[0]->newInstance()->id;
            if (is_string($id) && $id !== '' && class_exists($id)) {
                return $id;
            }
        }

        if ($declared instanceof ReflectionNamedType && !$declared->isBuiltin()) {
            return $declared->getName();
        }

        return null;
    }
}
