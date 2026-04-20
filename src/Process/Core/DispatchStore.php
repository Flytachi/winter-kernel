<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Core;

use Flytachi\Winter\Kernel\Kernel;

final class DispatchStore
{
    private static string $ES_NAME = 'dispatcher';

    final public static function push(string $storeKey, mixed $data): void
    {
        Kernel::volatile(self::$ES_NAME)->write($storeKey, $data);
    }

    final public static function pop(string $storeKey): mixed
    {
        $fs = Kernel::volatile(self::$ES_NAME);
        $data = $fs->read($storeKey);
        $fs->del($storeKey);
        return $data;
    }
}
