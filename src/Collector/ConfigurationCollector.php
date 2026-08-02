<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Collector;

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
 * parameter reads from .env, any other parameter is autowired from the container.
 *
 * ```
 *   Scanner::run($rootDir)->collect(new ConfigurationCollector($container))->execute();
 * ```
 */
final readonly class ConfigurationCollector implements CollectorInterface
{
    public function __construct(private Container $container)
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
            $factory = $this->factoryFor($class, $method->getName());

            match ($bean->scope) {
                Scope::Singleton => $this->container->singleton($key, $factory),
                Scope::Transient => $this->container->transient($key, $factory),
                Scope::Request   => $this->container->request($key, $factory),
            };
        }
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
