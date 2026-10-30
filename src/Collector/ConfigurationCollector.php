<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Collector;

use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\DI\Attribute\Request;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Attribute\Transient;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\Kernel\App\Attribute\Bean;
use Flytachi\Winter\Kernel\App\Attribute\Configuration;
use Flytachi\Winter\Kernel\App\Attribute\Value;
use Flytachi\Winter\Kernel\App\Scope;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Registers the {@see Bean} factory methods of every {@see Configuration} class
 * into the container — the winter analogue of Spring's @Configuration/@Bean.
 *
 * For each #[Configuration] class:
 *   - the class itself is registered as a singleton (so its bean methods share
 *     one instance, like a Spring @Configuration bean);
 *   - every #[Bean] method becomes a factory keyed by its return type (or the
 *     bean's explicit name), scoped per {@see Bean::$scope}.
 *
 * A bean method's parameters are resolved lazily at build time: a #[Value]
 * parameter reads from .env, an #[Inject] one resolves what it names, any other is
 * autowired from the container by its type.
 *
 * A bean with a name is keyed by the name, and is reachable by its type as well
 * when that does not take the type from anything else — see {@see finish()}.
 *
 * ```
 *   $config = new ConfigurationCollector($container);
 *   Scanner::run($rootDir)->collect($config)->execute();
 *   $config->finish();   // named beans by type, once every bean is known
 * ```
 */
final class ConfigurationCollector implements CollectorInterface
{
    /** @var array<class-string, list<string>> Names of the named beans, per return type. */
    private array $named = [];
    /** @var array<class-string, true> Return types an unnamed bean is keyed by. */
    private array $unnamed = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function collect(string $class, ReflectionClass $ref): void
    {
        if ($ref->getAttributes(Configuration::class) === []) {
            return;
        }

        // One shared instance of the configuration class for all of its beans.
        $this->container->singleton($class);

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(Bean::class);
            if ($attributes === []) {
                continue;
            }

            /** @var Bean $bean */
            $bean = $attributes[0]->newInstance();
            $key = $bean->name ?? self::returnTypeOf($method, $class);
            self::assertObjectReturn($method, $class);
            /** @var ReflectionNamedType $returns checked just above */
            $returns = $method->getReturnType();
            if ($bean->name !== null) {
                $this->named[$returns->getName()][] = $bean->name;
            } else {
                $this->unnamed[$returns->getName()] = true;
            }
            $factory = $this->factoryFor($class, $method->getName());

            match ($bean->scope) {
                Scope::Singleton => $this->container->singleton($key, $factory),
                Scope::Transient => $this->container->transient($key, $factory),
                Scope::Request   => $this->container->request($key, $factory),
            };
        }
    }

    /**
     * Makes the named beans reachable by their type, once every bean is known — so the
     * outcome does not depend on the order the scan met the configuration classes:
     *
     * - an unnamed bean of the type owns it: by type comes that one, the named ones by
     *   name only;
     * - so does a class registered by its own scope attribute (#[Singleton] and the
     *   like);
     * - otherwise one named bean of the type is that type too;
     * - otherwise, two or more named beans make injecting the type an error naming them
     *   — without it the container would quietly build a new object of the type, one
     *   that is none of the beans.
     *
     * @internal Called by the application after the scan.
     */
    public function finish(): void
    {
        foreach ($this->named as $type => $names) {
            if (isset($this->unnamed[$type]) || self::scopedByAttribute($type) || in_array($type, $names, true)) {
                continue;
            }
            $names = array_values(array_unique($names));
            if (count($names) === 1) {
                $name = $names[0];
                $this->container->bind($type, static fn(Container $c): mixed => $c->make($name));
                continue;
            }
            $this->container->bind($type, static function () use ($type, $names): never {
                throw new \RuntimeException(sprintf(
                    'There are %d beans of type %s: %s. Inject one by its name: #[Inject(\'%s\')].',
                    count($names),
                    $type,
                    implode(', ', $names),
                    $names[0],
                ));
            });
        }
    }

    /** Whether the class is registered by its own #[Singleton], #[Request] or #[Transient]. */
    private static function scopedByAttribute(string $type): bool
    {
        if (!class_exists($type)) {
            return false;
        }
        $ref = new ReflectionClass($type);
        return $ref->getAttributes(Singleton::class) !== []
            || $ref->getAttributes(Request::class) !== []
            || $ref->getAttributes(Transient::class) !== [];
    }

    /**
     * Builds the closure that invokes the bean method with resolved arguments.
     */
    private function factoryFor(string $class, string $method): \Closure
    {
        return function (Container $c) use ($class, $method): mixed {
            $config = $c->make($class);
            $args = self::resolveArguments($c, new ReflectionMethod($class, $method));
            return $config->{$method}(...$args);
        };
    }

    /**
     * @return list<mixed>
     */
    private static function resolveArguments(Container $c, ReflectionMethod $method): array
    {
        $args = [];
        foreach ($method->getParameters() as $parameter) {
            $args[] = self::resolveParameter($c, $parameter);
        }
        return $args;
    }

    private static function resolveParameter(Container $c, ReflectionParameter $parameter): mixed
    {
        $valueAttributes = $parameter->getAttributes(Value::class);
        if ($valueAttributes !== []) {
            /** @var Value $value */
            $value = $valueAttributes[0]->newInstance();
            return env($value->key, $value->default);
        }

        // As everywhere else in the container: a name or a class to resolve instead of
        // the type. Ignored here before, so a named bean asked for by name came by type.
        $injectAttributes = $parameter->getAttributes(Inject::class);
        if ($injectAttributes !== []) {
            /** @var Inject $inject */
            $inject = $injectAttributes[0]->newInstance();
            if ($inject->id !== null) {
                return $c->make($inject->id);
            }
        }

        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $c->make($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new \RuntimeException(sprintf(
            'Cannot resolve bean parameter $%s of %s::%s() — add a type hint, a #[Value], or a default.',
            $parameter->getName(),
            $parameter->getDeclaringClass()?->getName() ?? '?',
            $parameter->getDeclaringFunction()->getName(),
        ));
    }

    /**
     * A bean's value is stored in the container, which injects properties into
     * every resolved instance — so a bean must produce an object, never a scalar.
     * Scalars belong in .env / {@see Value}.
     */
    private static function assertObjectReturn(ReflectionMethod $method, string $class): void
    {
        $type = $method->getReturnType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new \RuntimeException(sprintf(
                '#[Bean] %s::%s() must return a class/interface — beans hold objects; '
                . 'use .env / #[Value] for scalar configuration.',
                $class,
                $method->getName(),
            ));
        }
    }

    private static function returnTypeOf(ReflectionMethod $method, string $class): string
    {
        $type = $method->getReturnType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new \RuntimeException(sprintf(
                '#[Bean] %s::%s() must declare a class/interface return type (the binding key), '
                . 'or pass an explicit name: #[Bean(name: ...)].',
                $class,
                $method->getName(),
            ));
        }
        return $type->getName();
    }
}
