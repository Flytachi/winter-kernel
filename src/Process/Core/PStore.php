<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Core;

use Flytachi\Winter\Kernel\Kernel;

final class PStore
{
    private static string $ES_NAME = 'threads/dispatcher';

    final public static function push(string $storeKey, mixed $data): void
    {
        Kernel::store(self::$ES_NAME)->write($storeKey, $data);
    }

    final public static function pop(string $storeKey): mixed
    {
        $data = Kernel::store(self::$ES_NAME)->read($storeKey);
        Kernel::store(self::$ES_NAME)->del($storeKey);
        return $data;
    }
}
