<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Repository;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures\UsersRepo;
use PHPUnit\Framework\TestCase;

final class BindsAndCacheTest extends TestCase
{
    // ── binding() — direct injection ────────────────────────────────────────

    public function test_binding_with_named_binds_records_under_prefixed_key(): void
    {
        $r = UsersRepo::instance()->binding([new CDOBind('user_id', 42)]);
        $binds = $r->getSql('binds');
        self::assertArrayHasKey(':user_id', $binds);
        self::assertSame(42, $binds[':user_id']->getValue());
    }

    public function test_binding_with_null_is_noop(): void
    {
        $r = UsersRepo::instance()->binding(null);
        self::assertNull($r->getSql('binds'));
    }

    public function test_binding_with_empty_array_is_noop(): void
    {
        $r = UsersRepo::instance()->binding([]);
        self::assertNull($r->getSql('binds'));
    }

    public function test_binding_merges_across_calls(): void
    {
        $r = UsersRepo::instance()
            ->binding([new CDOBind('a', 1)])
            ->binding([new CDOBind('b', 2)]);
        $binds = $r->getSql('binds');
        self::assertCount(2, $binds);
        self::assertArrayHasKey(':a', $binds);
        self::assertArrayHasKey(':b', $binds);
    }

    public function test_binding_same_name_overwrites_value(): void
    {
        $r = UsersRepo::instance()
            ->binding([new CDOBind('x', 1)])
            ->binding([new CDOBind('x', 99)]);
        self::assertSame(99, $r->getSql('binds')[':x']->getValue());
    }

    // ── cleanCache() ────────────────────────────────────────────────────────

    public function test_clean_cache_with_key_clears_only_that_part(): void
    {
        $r = UsersRepo::instance()
            ->where(Qb::eq('a', new CDOBind('a_v', 1)))
            ->orderBy('id DESC');

        $r->cleanCache('where');
        self::assertNull($r->getSql('where'));
        // Other parts preserved.
        self::assertSame('ORDER BY id DESC', $r->getSql('order'));
    }

    public function test_clean_cache_without_key_clears_everything(): void
    {
        $r = UsersRepo::instance()
            ->where(Qb::eq('a', new CDOBind('a_v', 1)))
            ->orderBy('id DESC')
            ->limit(10);

        $r->cleanCache();
        self::assertNull($r->getSql('where'));
        self::assertNull($r->getSql('order'));
        self::assertNull($r->getSql('limit'));
        // After full clear, buildSql produces the baseline SELECT.
        self::assertSame('SELECT * FROM users', $r->buildSql());
    }

    public function test_clean_cache_with_missing_key_is_safe(): void
    {
        $r = UsersRepo::instance();
        $r->cleanCache('where'); // nothing to clear — must not throw
        self::assertNull($r->getSql('where'));
    }

    public function test_sql_parts_count_grows_with_chained_calls(): void
    {
        $r = UsersRepo::instance();
        self::assertSame(0, $r->sqlPartsCount());
        $r->as('u');
        self::assertSame(1, $r->sqlPartsCount());
        $r->where(Qb::eq('a', new CDOBind('a_v', 1)));
        self::assertGreaterThanOrEqual(2, $r->sqlPartsCount());
    }
}
