<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Async\Proxy;

use Flytachi\Winter\K2\Dev\Async\Async;
use Flytachi\Winter\K2\Dev\Async\AsyncException;
use Flytachi\Winter\K2\Dev\Concurrent\Future;

/**
 * Turns a class carrying {@see Async} methods into the source of a subclass
 * that runs those methods on an executor.
 *
 * The generated class extends the original and overrides nothing else, which
 * has a pleasant consequence: there is only ever **one** object. `$this` inside
 * an inherited method is the proxy itself, so a call from one method of the
 * service to another annotated one still goes through the override. That is the
 * self-invocation hole every Spring user knows about, and subclass proxying
 * simply does not have it.
 *
 * It also implements {@see \Flytachi\Winter\DI\Contract\ProxyInterface}, so the
 * container keeps treating the instance as the original class: injection is
 * planned from it and `contextual()` factories — a per-class logger, say —
 * receive the name the developer wrote rather than the generated one.
 *
 * Generation is a build-time concern: everything that cannot be expressed
 * faithfully is reported here as an {@see AsyncException}, never at runtime.
 *
 * @internal
 */
final class ProxyGenerator
{
    /** Namespace generated classes are placed in. */
    public const string PROXY_NAMESPACE = 'Flytachi\\Winter\\K2\\Dev\\Async\\Proxy\\Generated';

    private const string PROXY_SUFFIX = '__Async';
    private const string SUPPORT = '\\Flytachi\\Winter\\K2\\Dev\\Async\\AsyncSupport';
    private const string EXECUTORS = '\\Flytachi\\Winter\\K2\\Dev\\Concurrent\\Executors';
    private const string CONTAINER = '\\Flytachi\\Winter\\DI\\Container';
    private const string PROXY_CONTRACT = '\\Flytachi\\Winter\\DI\\Contract\\ProxyInterface';

    private function __construct()
    {
    }

    /**
     * Returns the class name the proxy of the given class is generated under.
     *
     * @param string $class Fully qualified name of the original class.
     */
    public static function proxyClass(string $class): string
    {
        return self::PROXY_NAMESPACE . '\\'
            . str_replace('\\', '_', ltrim($class, '\\'))
            . self::PROXY_SUFFIX;
    }

    /**
     * Collects the methods of a class that are marked asynchronous.
     *
     * Inherited methods count too, so annotating a base class works.
     *
     * @param \ReflectionClass $class Class to inspect.
     * @return list<\ReflectionMethod> Methods carrying the attribute.
     */
    public static function asyncMethods(\ReflectionClass $class): array
    {
        $methods = [];
        foreach ($class->getMethods() as $method) {
            if ($method->getAttributes(Async::class) !== []) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * Renders the proxy of a class as PHP source.
     *
     * @param \ReflectionClass $class Class to proxy.
     * @return string Complete PHP file, opening tag included.
     * @throws AsyncException If the class or any of its methods breaks the contract.
     */
    public static function generate(\ReflectionClass $class): string
    {
        $methods = self::asyncMethods($class);
        if ($methods === []) {
            throw AsyncException::of(
                $class->getName(),
                'no method is marked with #[Async]',
                'Nothing to proxy.'
            );
        }

        self::assertProxyable($class);

        $body = '';
        foreach ($methods as $method) {
            $body .= self::renderMethod($method, $class);
        }

        $short = substr(self::proxyClass($class->getName()), strlen(self::PROXY_NAMESPACE) + 1);
        $modifiers = $class->isReadOnly() ? 'final readonly class' : 'final class';
        $namespace = self::PROXY_NAMESPACE;
        $contract = self::PROXY_CONTRACT;
        $origin = '\\' . $class->getName();
        $body = rtrim($body) . "\n";

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        /**
         * Generated asynchronous proxy for {$origin}.
         *
         * Do not edit — regenerated from the #[Async] attributes of the original.
         */
        {$modifiers} {$short} extends {$origin} implements {$contract}
        {
            public static function proxyTarget(): string
            {
                return {$origin}::class;
            }

        {$body}}

        PHP;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param \ReflectionClass $class Class to validate.
     * @throws AsyncException If the class cannot be subclassed by the generator.
     */
    private static function assertProxyable(\ReflectionClass $class): void
    {
        if ($class->isFinal()) {
            throw AsyncException::of(
                $class->getName(),
                'the class is final and cannot be extended',
                'Drop "final" from the class, or move the #[Async] method to a non-final service.'
            );
        }

        if ($class->isAbstract() || $class->isInterface() || $class->isEnum()) {
            throw AsyncException::of(
                $class->getName(),
                'only instantiable classes can be proxied',
                'Put #[Async] on the concrete service the container resolves.'
            );
        }

        if ($class->isInternal()) {
            throw AsyncException::of(
                $class->getName(),
                'internal classes cannot be proxied',
                'Wrap the call in a service of your own.'
            );
        }
    }

    /**
     * @param \ReflectionMethod $method Method to override.
     * @param \ReflectionClass $class Class being proxied.
     */
    private static function renderMethod(\ReflectionMethod $method, \ReflectionClass $class): string
    {
        $subject = $class->getName() . '::' . $method->getName() . '()';
        self::assertOverridable($method, $subject);

        $isVoid = self::returnsVoid($method, $subject);
        $attribute = $method->getAttributes(Async::class)[0]->newInstance();
        $executor = self::renderExecutor($attribute);

        $signature = sprintf(
            '%s function %s(%s)',
            $method->isProtected() ? 'protected' : 'public',
            $method->getName(),
            SignatureWriter::parameters($method)
        );

        $returnType = SignatureWriter::type($method->getReturnType(), $method->getDeclaringClass());
        $call = sprintf('parent::%s(%s)', $method->getName(), SignatureWriter::arguments($method));

        $line = $isVoid
            ? sprintf('%s::execute(%s, fn() => %s);', self::SUPPORT, $executor, $call)
            : sprintf('return %s::submit(%s, fn() => %s);', self::SUPPORT, $executor, $call);

        return <<<PHP
            /**
             * Runs \\{$subject} asynchronously.
             */
            {$signature}: {$returnType}
            {
                {$line}
            }


        PHP;
    }

    /**
     * @param \ReflectionMethod $method Method to validate.
     * @param string $subject Method name used in error messages.
     * @throws AsyncException If the method cannot be overridden.
     */
    private static function assertOverridable(\ReflectionMethod $method, string $subject): void
    {
        $problem = match (true) {
            $method->isFinal() => ['the method is final', 'Drop "final" so the proxy can override it.'],
            $method->isStatic() => [
                'the method is static',
                'Asynchrony is applied per instance; make the method non-static.',
            ],
            $method->isAbstract() => [
                'the method is abstract',
                'Put #[Async] on the implementation instead.',
            ],
            $method->isPrivate() => [
                'the method is private',
                'A private method is resolved statically inside its own class, so no subclass can '
                . 'intercept it. Make it protected — self-calls still go through the proxy.',
            ],
            default => null,
        };

        if ($problem !== null) {
            throw AsyncException::of($subject, $problem[0], $problem[1]);
        }
    }

    /**
     * @param \ReflectionMethod $method Method to validate.
     * @param string $subject Method name used in error messages.
     * @return bool True when the method returns void.
     * @throws AsyncException If the return type is not void or Future.
     */
    private static function returnsVoid(\ReflectionMethod $method, string $subject): bool
    {
        $type = $method->getReturnType();
        $remedy = sprintf(
            'Declare "void" for fire-and-forget, or "\\%s" and return CompletableFuture::completedFuture($value).',
            Future::class
        );

        if (!$type instanceof \ReflectionNamedType) {
            throw AsyncException::of(
                $subject,
                $type === null ? 'the method has no return type' : 'the return type is not a single named type',
                $remedy
            );
        }

        $name = strtolower($type->getName());

        if ($name === 'void') {
            return true;
        }

        if (!$type->allowsNull() && $type->getName() === Future::class) {
            return false;
        }

        throw AsyncException::of(
            $subject,
            'the return type is "' . $type . '", which the proxy cannot produce',
            $remedy
        );
    }

    /**
     * @param Async $attribute Attribute instance carrying the executor id.
     */
    private static function renderExecutor(Async $attribute): string
    {
        if ($attribute->executor === null) {
            return self::EXECUTORS . '::common()';
        }

        return sprintf(
            '%s::getInstance()->get(%s)',
            self::CONTAINER,
            var_export($attribute->executor, true)
        );
    }
}
