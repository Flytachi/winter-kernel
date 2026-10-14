<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Concurrent\Async;

use Flytachi\Winter\Kernel\Concurrent\Future;
use Flytachi\Winter\Kernel\Core\Proxy\MethodInterceptor;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyException;

/**
 * Renders {@see Async} methods: the override hands the original body to an
 * executor and returns at once.
 *
 * @internal
 */
final class AsyncInterceptor implements MethodInterceptor
{
    private const string SUPPORT = '\\Flytachi\\Winter\\Kernel\\Concurrent\\Async\\AsyncSupport';
    private const string EXECUTORS = '\\Flytachi\\Winter\\Kernel\\Concurrent\\Executors';
    private const string CONTAINER = '\\Flytachi\\Winter\\DI\\Container';

    public function name(): string
    {
        return 'async';
    }

    public function label(): string
    {
        return '#[Async]';
    }

    public function intercepts(\ReflectionMethod $method): bool
    {
        return $method->getAttributes(Async::class) !== [];
    }

    public function body(\ReflectionMethod $method, \ReflectionClass $class, string $call): string
    {
        $subject = $class->getName() . '::' . $method->getName() . '()';

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isPassedByReference()) {
                throw $this->error(
                    $subject,
                    'parameter $' . $parameter->getName() . ' is passed by reference',
                    'An asynchronous call returns before the body runs, so writes to it could never be observed. '
                    . 'Return the value instead.'
                );
            }
        }

        $isVoid = $this->returnsVoid($method, $subject);
        $executor = $this->renderExecutor($method->getAttributes(Async::class)[0]->newInstance());

        return $isVoid
            ? sprintf('%s::execute(%s, fn() => %s);', self::SUPPORT, $executor, $call)
            : sprintf('return %s::submit(%s, fn() => %s);', self::SUPPORT, $executor, $call);
    }

    public function error(string $subject, string $problem, string $remedy): ProxyException
    {
        return AsyncException::of($subject, $problem, $remedy);
    }

    /**
     * @param \ReflectionMethod $method Method to validate.
     * @param string $subject Method name used in error messages.
     * @return bool True when the method returns void.
     * @throws AsyncException If the return type is not void or Future.
     */
    private function returnsVoid(\ReflectionMethod $method, string $subject): bool
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

        if (strtolower($type->getName()) === 'void') {
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
    private function renderExecutor(Async $attribute): string
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
