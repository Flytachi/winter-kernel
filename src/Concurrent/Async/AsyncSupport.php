<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Concurrent\Async;

use Flytachi\Winter\K2\Concurrent\ExecutorService;
use Flytachi\Winter\K2\Concurrent\Future;

/**
 * Runtime helper called by generated proxies.
 *
 * Its only job is unwrapping: the body of a `Future`-returning `#[Async]`
 * method hands back a already-settled future, while the caller expects the
 * *value* of that future. Without this step `get()` would return a Future
 * wrapped in a Future.
 *
 * The same unwrapping exists in Spring as `AsyncExecutionAspectSupport`.
 *
 * @internal Not part of the public API; generated code depends on it.
 */
final class AsyncSupport
{
    private function __construct()
    {
    }

    /**
     * Runs the method body asynchronously and flattens a nested future.
     *
     * @param ExecutorService $executor Executor to run on.
     * @param \Closure $body Bound call to the original method.
     */
    public static function submit(ExecutorService $executor, \Closure $body): Future
    {
        return $executor->submit(static function () use ($body): mixed {
            $result = $body();

            return $result instanceof Future ? $result->get() : $result;
        });
    }

    /**
     * Runs a void method body asynchronously, discarding its outcome.
     *
     * @param ExecutorService $executor Executor to run on.
     * @param \Closure $body Bound call to the original method.
     */
    public static function execute(ExecutorService $executor, \Closure $body): void
    {
        $executor->execute($body);
    }
}
