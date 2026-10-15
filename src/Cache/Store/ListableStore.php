<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache\Store;

/**
 * A store whose entries outlive the server process, so a console command can list
 * them.
 *
 * A table or a memory cache lives in the memory of the running server; a separate
 * `call cache show` process would only ever see its own, empty copy. File and Redis
 * entries are on disk or on a server, the same for every process.
 *
 * @internal
 */
interface ListableStore extends CacheStore
{
    /**
     * Lists entries, expired ones that are still stored included.
     *
     * @param int|null $limit Most entries to return; null returns all.
     * @return list<array{key: string, expiresIn: int|null, bytes: int}> Seconds left (null = never) and value size.
     */
    public function entries(?int $limit = null): array;

    /**
     * Where the entries live, for a human: a folder or a Redis prefix.
     */
    public function location(): string;
}
