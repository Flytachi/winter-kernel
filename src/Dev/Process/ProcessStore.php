<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\K2\Kernel;

/**
 * Locates the runnable store for a process class.
 *
 * One record per class, keyed by the dotted class name — the same convention
 * as {@see \Flytachi\Winter\K2\Process\Core\DaemonStore}, so CLI and web read
 * from a single place.
 */
final class ProcessStore
{
    private string $key;

    public function __construct(string $className)
    {
        $this->key = str_replace('\\', '.', $className);
    }

    public function main(): FileStorage
    {
        return Kernel::runnable($this->key);
    }
}
