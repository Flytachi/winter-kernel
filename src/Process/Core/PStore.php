<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Core;

use Flytachi\Winter\Kernel\Kernel;

final class PStore
{
    private static string $ES_NAME = 'threads/dispatcher';

    final public static function push(string $filename, mixed $data): void
    {
        Kernel::store(self::$ES_NAME)->write($filename, $data);
    }

    final public static function pop(string $filename): mixed
    {
        $data = Kernel::store(self::$ES_NAME)->read($filename);
        Kernel::store(self::$ES_NAME)->del($filename);
        return $data;
    }
}
