<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Operation;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\Thread\Thread;

/**
 * Entry point for dispatching background tasks.
 *
 * This is a static-only utility class that wraps any callable in an
 * {@see OperationRunnable}, spawns a {@see Thread} (child process),
 * and returns a {@see Future} to optionally await the result.
 *
 * The class is final and cannot be instantiated.
 *
 * ---
 * ### Example
 *
 * ```
 * // Await result
 * $result = Operation::async(fn() => heavyJob())->return();
 *
 * // Fire-and-forget
 * Operation::async(fn() => sendEmail($to, $subject, $body));
 * ```
 *
 * @see Future
 * @see OperationRunnable
 */
final class Operation
{
    private function __construct()
    {
    }

    /**
     * Returns the shared volatile store used for inter-process result passing.
     *
     * @return FileStorage The shared operation store instance.
     */
    public static function store(): FileStorage
    {
        return Kernel::volatile('operations');
    }

    /**
     * Dispatches a callable to run asynchronously in a background process.
     *
     * @template TResult
     *
     * @param callable(): TResult $callback Any PHP callable: closure, named function, or invokable object.
     * @return Future<TResult> A handle to the running background operation.
     */
    public static function async(callable $callback): Future
    {
        $runnable = new OperationRunnable($callback);

        $thread = new Thread(
            $runnable,
            'operation',
            $runnable->getName()
        );

        return new Future($runnable->getId(), $thread);
    }
}
