<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Operation;

use Closure;
use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Thread\Thread;

final class Operation
{
    private function __construct()
    {
    }

    public static function store(): FileStorage
    {
        return Kernel::volatile('operations');
    }

    public static function async(callable|Closure $callback): Future
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
