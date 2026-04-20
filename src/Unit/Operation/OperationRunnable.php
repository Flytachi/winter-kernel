<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Operation;

use Flytachi\Winter\Base\Algorithm;
use Flytachi\Winter\Thread\Runnable;

/**
 * Internal Runnable that wraps a user-provided callable for background execution.
 *
 * This class is created exclusively by {@see Operation::async()} and should
 * never be instantiated directly by application code.
 *
 * On construction:
 * - Generates a unique operation ID ("op_" + 16 random chars).
 * - Converts the callable to a {@see \Closure} for safe storage and invocation.
 * - Resolves a human-readable task name via reflection (used as the OS process title).
 *
 * During {@see run()}, the callback is executed inside the child process.
 * The result (or any caught {@see \Throwable}) is written to the shared store
 * only if the parent's {@see Future} is still waiting (i.e. "pending" marker exists).
 *
 * @see Operation::async()
 * @see Future
 */
final class OperationRunnable implements Runnable
{
    private string $id;
    private string $name;
    private \Closure $callback;

    /**
     * @template TResult
     * @param callable(): TResult $callback
     */
    public function __construct(callable $callback)
    {
        $this->id = 'op_' . Algorithm::random(16);
        $this->callback = \Closure::fromCallable($callback);
        $this->name = $this->callableName();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function run(array $args): void
    {
        try {
            $result = ($this->callback)();
        } catch (\Throwable $throwable) {
        } finally {
            $pending = Operation::store()->read($this->id);
            if ($pending === 'pending') {
                Operation::store()->write(
                    $this->id,
                    new OpResult(
                        $result ?? null,
                        $throwable ?? null
                    )
                );
            }
        }
    }

    private function callableName(): string
    {
        $reflection = new \ReflectionFunction($this->callback);

        if ($reflection->isClosure()) {
            $class = $reflection->getClosureScopeClass();
            if ($class) {
                return '[closure] in ' . $class->getName();
            }
            return '[closure]';
        }

        $name = $reflection->getName();
        if (str_contains($name, '{closure}')) {
            return '[closure]';
        }

        return '[function] ' . $name;
    }
}
