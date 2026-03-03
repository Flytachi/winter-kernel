<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Core;

use Flytachi\Winter\Kernel\Kernel;

final class DispatchStore
{
    private static string $ES_NAME = 'dispatcher';

    final public static function push(string $storeKey, mixed $data): void
    {
        Kernel::volatiles(self::$ES_NAME)->write($storeKey, $data);
    }

    final public static function pop(string $storeKey): mixed
    {
        $data = Kernel::volatiles(self::$ES_NAME)->read($storeKey);
        Kernel::volatiles(self::$ES_NAME)->del($storeKey);
        return $data;
    }
}
