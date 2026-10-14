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
}
