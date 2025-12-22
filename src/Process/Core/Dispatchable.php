<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Core;

use Flytachi\Winter\Thread\Runnable;

interface Dispatchable extends Runnable
{
    public static function dispatch(mixed $data = null): int;
    public static function start(mixed $data = null): void;
    public function resolution(mixed $data = null): void;
}