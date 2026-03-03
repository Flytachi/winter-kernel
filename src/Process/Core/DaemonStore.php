<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Core;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Kernel\Kernel;

final class DaemonStore
{
    private string $mainKey;

    final public function __construct(string $className)
    {
        $this->mainKey = str_replace('\\', '.', $className);
    }

    final public function main(): FileStorage
    {
        return Kernel::runnable($this->mainKey);
    }

    final public function threads(): FileStorage
    {
        return Kernel::runnable($this->mainKey . '/threads', false);
    }
}
