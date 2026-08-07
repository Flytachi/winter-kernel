<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Kernel\Kernel;

/**
 * Locates the runnable store for a process class.
 *
 * One record per class, keyed by the dotted class name, so CLI and web read from
 * a single place.
 */
final class ProcessStore
{
    private string $key;

    /**
     * @param string $className Process class whose record this store addresses;
     *                          the dotted form is the on-disk key.
     */
    public function __construct(string $className)
    {
        $this->key = str_replace('\\', '.', $className);
    }

    /**
     * The backing {@see FileStorage} for this class's runnable records.
     */
    public function main(): FileStorage
    {
        return Kernel::runnable($this->key);
    }
}
