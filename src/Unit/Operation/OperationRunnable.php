<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Operation;

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
 * @package Flytachi\Winter\Kernel\Unit\Operation
 * @see Operation::async()
 * @see Future
 */
final class OperationRunnable implements Runnable
{
    /**
     * Unique identifier for this operation.
     * Format: "op_" followed by 16 random alphanumeric characters.
     *
     * @var string
     */
    private string $id;

    /**
     * Human-readable name resolved from the callable via reflection.
     * Used as the Thread process name for OS-level identification.
     *
     * @var string
     */
    private string $name;

    /**
     * The callable to execute in the child process, stored as a Closure.
     *
     * @var \Closure
     */
    private \Closure $callback;

    /**
     * Creates a new OperationRunnable wrapping the given callable.
     *
     * @template TResult
     * @param callable(): TResult $callback Any PHP callable. Converted to {@see \Closure} internally.
     */
    public function __construct(callable $callback)
    {
        $this->id = 'op_' . Algorithm::random(16);
        $this->callback = \Closure::fromCallable($callback);
        $this->name = $this->callableName();
    }

    /**
     * Returns the unique operation ID.
     *
     * Used as the key for reading and writing results in the shared store.
     *
     * @return string The operation ID, e.g. "op_a3f9c21b7e04d815".
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the human-readable name of the task.
     *
     * Resolved at construction via reflection. Passed to {@see Thread} as the process name.
     *
     * Examples:
     * - Closure inside a class  → "[closure] in App\Service\MyService"
     * - Anonymous closure       → "[closure]"
     * - Named function          → "[function] myFunctionName"
     *
     * @return string The resolved task name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Executes the callback inside the child process and persists the result.
     *
     * Called by the {@see \Flytachi\Winter\Thread\Thread} system in the child process.
     *
     * Execution flow:
     * 1. Invokes the stored closure.
     * 2. In the `finally` block, reads the store under {@see $id}.
     * 3. If the value is still "pending" (parent Future is alive and waiting),
     *    writes an {@see OpResult} containing the return value or caught {@see \Throwable}.
     * 4. If the value is absent (parent Future was destroyed → fire-and-forget),
     *    the result is silently discarded.
     *
     * @param array $args Arguments passed by the Thread system (unused).
     *
     * @return void
     */
    public function run(array $args): void
    {
        try {
            $result = ($this->callback)();
        } catch (\Throwable $throwable) {
        } finally {
            $pending = Operation::store()->read($this->id);
            if ($pending === 'pending') {
                Operation::store()->write($this->id,
                    new OpResult(
                        $result ?? null,
                        $throwable ?? null
                    )
                );
            }
        }
    }

    /**
     * Resolves a human-readable name from the stored callable using reflection.
     *
     * @return string The resolved display name.
     */
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
