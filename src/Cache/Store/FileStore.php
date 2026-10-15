<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache\Store;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Kernel\Cache\CacheDefinition;
use Flytachi\Winter\Kernel\Kernel;

/**
 * Entries kept as files under `storage/cache/winter-cache/<name>`, through the
 * kernel's own file-store.
 *
 * Writes are atomic — a temporary file renamed into place — so a reader in another
 * worker never sees half a value. Expiry is file-store's: the deadline is the first
 * line of the file, and an expired file is removed when it is read or swept.
 *
 * File names are hashed, so the original key is written into the file next to the
 * value: that is what lets `call cache show` print keys a human can recognise.
 *
 * @internal
 */
final class FileStore implements ListableStore
{
    /** Folder mode file-store itself would use. */
    private const int DIRECTORY_MODE = 0770;

    /** Marks the deadline line file-store writes in front of an expiring value. */
    private const string EXPIRY_MARK = '#^e:';

    private readonly FileStorage $files;
    private readonly string $directory;

    public function __construct(private readonly CacheDefinition $definition)
    {
        Kernel::ensureDirectory(Kernel::$pathStorageCache);
        $this->directory = rtrim(Kernel::$pathStorageCache, '/') . '/' . $definition->fileFolder();

        // Created here, tolerating a race: under coroutine hooks `mkdir` yields, and two
        // first calls in one worker would both find the folder missing — the second
        // mkdir fails with "File exists", and file-store treats that as fatal.
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, self::DIRECTORY_MODE, true);
        }

        $this->files = new FileStorage(Kernel::$pathStorageCache, $definition->fileFolder());
    }

    public function get(string $key): ?string
    {
        $entry = $this->files->read($key);

        return is_array($entry) && is_string($entry['v'] ?? null) ? $entry['v'] : null;
    }

    public function put(string $key, string $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->definition->ttl ?? 0;

        // file-store keeps an entry while its deadline is not yet *past*, a second longer
        // than the other stores, which drop it once the deadline is reached.
        $this->files->write($key, ['k' => $key, 'v' => $value], $ttl > 0 ? time() + $ttl - 1 : null);

        return true;
    }

    public function evict(string $key): void
    {
        $this->files->del($key);
    }

    public function clear(): void
    {
        $this->files->clear();
    }

    public function count(): int
    {
        return count($this->files->keys());
    }

    /**
     * Removes expired files and temporary leftovers of interrupted writes.
     *
     * @return int Files removed.
     */
    public function sweep(): int
    {
        return $this->files->gc();
    }

    public function entries(?int $limit = null): array
    {
        $entries = [];
        $now = time();

        foreach ($this->files->keys() as $name) {
            if ($limit !== null && count($entries) >= $limit) {
                break;
            }

            $data = @file_get_contents($this->directory . '/' . $name);
            if ($data === false) {
                continue;
            }

            // The layout file-store writes: an optional deadline line, then the value.
            $expiresAt = null;
            if (str_starts_with($data, self::EXPIRY_MARK)) {
                $newline = (int) strpos($data, "\n");
                $expiresAt = (int) substr($data, strlen(self::EXPIRY_MARK), $newline - strlen(self::EXPIRY_MARK));
                $data = substr($data, $newline + 1);
            }

            $entry = @unserialize($data);
            if (!is_array($entry) || !is_string($entry['k'] ?? null) || !is_string($entry['v'] ?? null)) {
                continue;
            }

            $entries[] = [
                'key' => $entry['k'],
                'expiresIn' => $expiresAt === null ? null : max(0, $expiresAt + 1 - $now),
                'bytes' => strlen($entry['v']),
            ];
        }

        return $entries;
    }

    public function location(): string
    {
        return $this->directory;
    }
}
