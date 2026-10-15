<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache;

/**
 * Where a cache keeps its entries.
 */
enum CacheType: string
{
    /** A `Swoole\Table` created in the master: one copy shared by every process forked from it. */
    case Table = 'table';

    /** A PHP array inside each worker: no sizes to plan, but one copy per worker. */
    case Memory = 'memory';

    /** Files under `storage/cache`: survive restarts and are seen by console commands. */
    case File = 'file';

    /** A Redis database: shared by every instance of the application. */
    case Redis = 'redis';

    /**
     * Whether the entries outlive the server process, so a console command can see
     * and clear them.
     */
    public function isPersistent(): bool
    {
        return $this === self::File || $this === self::Redis;
    }
}
