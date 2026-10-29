<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Cache;

use Flytachi\Winter\Kernel\Core\KernelConfig;
use ReflectionProperty;

/**
 * Points `.winter/cache` at a throw-away directory, so a file cache under test never
 * touches the project's own storage.
 */
trait UsesTemporaryStorage
{
    private ?string $originalStorageCache = null;
    private string $storageCache = '';

    private function useTemporaryStorage(): void
    {
        $property = new ReflectionProperty(KernelConfig::class, 'pathCache');
        $this->originalStorageCache = $property->isInitialized() ? $property->getValue() : null;

        $this->storageCache = sys_get_temp_dir() . '/wk_cache_storage_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->storageCache);
        KernelConfig::$pathCache = $this->storageCache;
    }

    private function dropTemporaryStorage(): void
    {
        if ($this->storageCache !== '') {
            self::removeTree($this->storageCache);
            $this->storageCache = '';
        }

        if ($this->originalStorageCache !== null) {
            KernelConfig::$pathCache = $this->originalStorageCache;
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
