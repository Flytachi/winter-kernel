<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Core\Proxy;

/**
 * Turns a class whose methods carry interceptable attributes into the source of a
 * subclass that overrides exactly those methods.
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
 * What each override does is decided by a {@see MethodInterceptor}; this class only
 * finds the methods, checks they can be overridden at all, and writes the shell.
 *
 * Generation is a build-time concern: everything that cannot be expressed
 * faithfully is reported here as a {@see ProxyException}, never at runtime.
 *
 * @internal
 */
final class ProxyGenerator
{
    /** Namespace generated classes are placed in. */
    public const string PROXY_NAMESPACE = 'Flytachi\\Winter\\Kernel\\Core\\Proxy\\Generated';

    /** Docblock line naming the interceptors a file was generated for. */
    public const string MARKER = ' * Interceptors: ';

    private const string PROXY_SUFFIX = '__Proxy';
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
     * Identifies a set of interceptors, independent of their order.
     *
     * @param list<MethodInterceptor> $interceptors
     */
    public static function fingerprint(array $interceptors): string
    {
        $names = array_map(static fn(MethodInterceptor $i): string => $i->name(), $interceptors);
        sort($names);

        return implode(', ', $names);
    }

    /**
     * Collects the methods of a class claimed by one of the interceptors.
     *
     * Inherited methods count too, so annotating a base class works.
     *
     * @param \ReflectionClass $class Class to inspect.
     * @param list<MethodInterceptor> $interceptors Enabled features.
     * @return list<array{\ReflectionMethod, MethodInterceptor}> Each method with the interceptor that claims it.
     * @throws ProxyException If one method is claimed by two interceptors.
     */
    public static function interceptedMethods(\ReflectionClass $class, array $interceptors): array
    {
        $claimed = [];
        foreach ($class->getMethods() as $method) {
            $owner = null;
            foreach ($interceptors as $interceptor) {
                if (!$interceptor->intercepts($method)) {
                    continue;
                }

                if ($owner !== null) {
                    throw ProxyException::of(
                        $class->getName() . '::' . $method->getName() . '()',
                        'the method carries both ' . $owner->label() . ' and ' . $interceptor->label(),
                        'They cannot be combined on one method; split it into two methods, '
                        . 'one calling the other.'
                    );
                }
                $owner = $interceptor;
            }

            if ($owner !== null) {
                $claimed[] = [$method, $owner];
            }
        }

        return $claimed;
    }

    /**
     * Renders the proxy of a class as PHP source.
     *
     * @param \ReflectionClass $class Class to proxy.
     * @param list<MethodInterceptor> $interceptors Enabled features.
     * @return string Complete PHP file, opening tag included.
     * @throws ProxyException If the class or any of its methods breaks a contract.
     */
    public static function generate(\ReflectionClass $class, array $interceptors): string
    {
        $methods = self::interceptedMethods($class, $interceptors);
        if ($methods === []) {
            throw ProxyException::of(
                $class->getName(),
                'no method carries an attribute that needs a proxy',
                'Nothing to proxy.'
            );
        }

        self::assertProxyable($class, $methods[0][1]);

        $body = '';
        foreach ($methods as [$method, $interceptor]) {
            $body .= self::renderMethod($method, $class, $interceptor);
        }

        $short = substr(self::proxyClass($class->getName()), strlen(self::PROXY_NAMESPACE) + 1);
        $modifiers = $class->isReadOnly() ? 'final readonly class' : 'final class';
        $namespace = self::PROXY_NAMESPACE;
        $contract = self::PROXY_CONTRACT;
        $origin = '\\' . $class->getName();
        $marker = self::MARKER . self::fingerprint($interceptors);
        $body = rtrim($body) . "\n";

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        /**
         * Generated proxy for {$origin}.
         *
        {$marker}
         *
         * Do not edit — regenerated from the attributes of the original.
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
     * @param MethodInterceptor $interceptor Feature the class is proxied for, used for messages.
     * @throws ProxyException If the class cannot be subclassed by the generator.
     */
    private static function assertProxyable(\ReflectionClass $class, MethodInterceptor $interceptor): void
    {
        $label = $interceptor->label();

        if ($class->isFinal()) {
            throw $interceptor->error(
                $class->getName(),
                'the class is final and cannot be extended',
                'Drop "final" from the class, or move the ' . $label . ' method to a non-final service.'
            );
        }

        if ($class->isAbstract() || $class->isInterface() || $class->isEnum()) {
            throw $interceptor->error(
                $class->getName(),
                'only instantiable classes can be proxied',
                'Put ' . $label . ' on the concrete service the container resolves.'
            );
        }

        if ($class->isInternal()) {
            throw $interceptor->error(
                $class->getName(),
                'internal classes cannot be proxied',
                'Wrap the call in a service of your own.'
            );
        }
    }

    /**
     * @param \ReflectionMethod $method Method to override.
     * @param \ReflectionClass $class Class being proxied.
     * @param MethodInterceptor $interceptor Feature that renders the body.
     */
    private static function renderMethod(
        \ReflectionMethod $method,
        \ReflectionClass $class,
        MethodInterceptor $interceptor,
    ): string {
        $subject = $class->getName() . '::' . $method->getName() . '()';
        self::assertOverridable($method, $subject, $interceptor);

        try {
            $signature = sprintf(
                '%s function %s(%s)',
                $method->isProtected() ? 'protected' : 'public',
                $method->getName(),
                SignatureWriter::parameters($method)
            );
            $returnType = SignatureWriter::type($method->getReturnType(), $method->getDeclaringClass());
        } catch (ProxyException $e) {
            // Reported as the feature's own error, so a caller catching AsyncException
            // still catches a signature the generator could not reproduce.
            throw $interceptor->error($subject, 'the signature cannot be reproduced', $e->getMessage());
        }

        $call = sprintf('parent::%s(%s)', $method->getName(), SignatureWriter::arguments($method));
        $statements = $interceptor->body($method, $class, $call);
        $indented = preg_replace('/^(?=.)/m', '        ', rtrim($statements));
        $returns = $returnType === '' ? '' : ': ' . $returnType;
        $label = $interceptor->label();

        return <<<PHP
            /**
             * Intercepts \\{$subject} for {$label}.
             */
            {$signature}{$returns}
            {
        {$indented}
            }


        PHP;
    }

    /**
     * @param \ReflectionMethod $method Method to validate.
     * @param string $subject Method name used in error messages.
     * @param MethodInterceptor $interceptor Feature the method is proxied for.
     * @throws ProxyException If the method cannot be overridden.
     */
    private static function assertOverridable(
        \ReflectionMethod $method,
        string $subject,
        MethodInterceptor $interceptor,
    ): void {
        $label = $interceptor->label();
        $problem = match (true) {
            $method->isFinal() => ['the method is final', 'Drop "final" so the proxy can override it.'],
            $method->isStatic() => [
                'the method is static',
                $label . ' is applied per instance; make the method non-static.',
            ],
            $method->isAbstract() => [
                'the method is abstract',
                'Put ' . $label . ' on the implementation instead.',
            ],
            $method->isPrivate() => [
                'the method is private',
                'A private method is resolved statically inside its own class, so no subclass can '
                . 'intercept it. Make it protected — self-calls still go through the proxy.',
            ],
            default => null,
        };

        if ($problem !== null) {
            throw $interceptor->error($subject, $problem[0], $problem[1]);
        }
    }
}
