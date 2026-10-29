<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Cache;

use Flytachi\Winter\Kernel\Cache\CacheDefinition;
use Flytachi\Winter\Kernel\Cache\CacheType;
use Flytachi\Winter\Kernel\Cache\Store\FileStore;
use PHPUnit\Framework\TestCase;

/**
 * A file cache's folder is made by its first write — not by declaring the cache, opening
 * its store, or the sweep every server start runs. A cache never written leaves nothing
 * in `.winter/cache`, and a missing folder reads as an empty cache.
 */
final class FileStoreLazyFolderTest extends TestCase
{
    use UsesTemporaryStorage;

    protected function setUp(): void
    {
        $this->useTemporaryStorage();
    }

    protected function tearDown(): void
    {
        $this->dropTemporaryStorage();
    }

    private static function store(): FileStore
    {
        return new FileStore(new CacheDefinition('prices', CacheType::File, 0, null, 300));
    }

    public function test_reading_an_unwritten_cache_makes_no_folder(): void
    {
        $store = self::store();

        self::assertNull($store->get('a'));
        self::assertSame(0, $store->count());
        self::assertSame([], $store->entries());
        self::assertSame(0, $store->sweep(), 'the start-up sweep');
        $store->evict('a');
        $store->clear();

        self::assertDirectoryDoesNotExist($store->location());
    }

    public function test_the_first_write_makes_the_folder_and_the_value_reads_back(): void
    {
        $store = self::store();

        self::assertTrue($store->put('a', 'one'));

        self::assertDirectoryExists($store->location());
        self::assertSame('one', $store->get('a'));
        self::assertSame(1, $store->count());
    }

    /** Another worker wrote first: this one finds the folder and its entries. */
    public function test_a_folder_made_by_another_process_is_read(): void
    {
        self::store()->put('a', 'one');

        self::assertSame('one', self::store()->get('a'));
    }
}
