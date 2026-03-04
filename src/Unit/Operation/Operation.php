<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Operation;

use Closure;
use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Kernel\Kernel;
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
 * @package Flytachi\Winter\Kernel\Unit\Operation
 * @see Future
 * @see OperationRunnable
 */
final class Operation
{
    /**
     * @internal Not instantiable.
     */
    private function __construct()
    {
    }

    /**
     * Returns the shared volatile store used for inter-process result passing.
     *
     * The store is backed by a temporary/in-memory storage bucket named "operations".
     * Used internally by {@see Future} and {@see OperationRunnable} to read, write,
     * and delete operation results identified by their unique operation ID.
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
     * Wraps the callable in an {@see OperationRunnable}, creates a {@see Thread},
     * and returns a {@see Future} that starts the thread immediately.
     *
     * The callable can return any value or throw any {@see \Throwable}.
     * Both are captured and made available via {@see Future::return()}.
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
