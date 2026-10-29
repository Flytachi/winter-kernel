<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache\Store;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Kernel\Cache\CacheDefinition;
use Flytachi\Winter\Kernel\Kernel;

/**
 * Entries kept as files under `.winter/cache/<name>`, through the
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

    private ?FileStorage $files = null;
    private readonly string $directory;

    /**
     * Touches nothing on disk: the folder is made by the first write. A cache declared
     * but never written — or only read, swept or counted — leaves no folder behind, and
     * a missing folder reads as an empty cache.
     */
    public function __construct(private readonly CacheDefinition $definition)
    {
        $this->directory = rtrim(Kernel::$pathCache, '/') . '/' . $definition->fileFolder();
    }

    public function get(string $key): ?string
    {
        $entry = $this->files()?->read($key);

        return is_array($entry) && is_string($entry['v'] ?? null) ? $entry['v'] : null;
    }

    public function put(string $key, string $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->definition->ttl ?? 0;

        // file-store keeps an entry while its deadline is not yet *past*, a second longer
        // than the other stores, which drop it once the deadline is reached.
        $this->files(create: true)?->write($key, ['k' => $key, 'v' => $value], $ttl > 0 ? time() + $ttl - 1 : null);

        return true;
    }

    public function evict(string $key): void
    {
        $this->files()?->del($key);
    }

    public function clear(): void
    {
        $this->files()?->clear();
    }

    public function count(): int
    {
        return count($this->files()?->keys() ?? []);
    }

    /**
     * Removes expired files and temporary leftovers of interrupted writes.
     *
     * @return int Files removed.
     */
    public function sweep(): int
    {
        return $this->files()?->gc() ?? 0;
    }

    public function entries(?int $limit = null): array
    {
        $entries = [];
        $now = time();

        foreach ($this->files()?->keys() ?? [] as $name) {
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

    /**
     * The folder's storage, opened once. Null while the folder does not exist and the
     * caller only reads: there is nothing in it to find.
     */
    private function files(bool $create = false): ?FileStorage
    {
        if ($this->files !== null) {
            return $this->files;
        }
        if (!is_dir($this->directory)) {
            if (!$create) {
                return null;
            }
            Kernel::ensureDirectory(Kernel::$pathCache);
            // Tolerating a race: under coroutine hooks `mkdir` yields, and two first
            // writes in one worker would both find the folder missing — the second mkdir
            // fails with "File exists", and file-store treats that as fatal.
            @mkdir($this->directory, self::DIRECTORY_MODE, true);
        }

        return $this->files = new FileStorage(Kernel::$pathCache, $this->definition->fileFolder());
    }
}
