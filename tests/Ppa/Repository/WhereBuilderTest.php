<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Repository;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Kernel\Tests\Ppa\Repository\Fixtures\UsersRepo;
use PHPUnit\Framework\TestCase;

final class WhereBuilderTest extends TestCase
{
    // ── where() — single predicate ──────────────────────────────────────────

    public function test_where_with_named_bind_emits_stable_placeholder(): void
    {
        $sql = UsersRepo::instance()
            ->where(Qb::eq('status', new CDOBind('status_v', 'active')))
            ->buildSql();
        self::assertSame('SELECT * FROM users WHERE status = :status_v', $sql);
    }

    public function test_where_null_argument_is_a_noop(): void
    {
        $r = UsersRepo::instance()->where(null);
        self::assertNull($r->getSql('where'));
    }

    public function test_where_with_empty_Qb_is_a_noop(): void
    {
        $r = UsersRepo::instance()->where(Qb::empty());
        self::assertNull($r->getSql('where'));
    }

    public function test_where_records_bind_in_binds_part(): void
    {
        $r = UsersRepo::instance()->where(Qb::eq('id', new CDOBind('user_id', 42)));
        $binds = $r->getSql('binds');
        self::assertArrayHasKey(':user_id', $binds);
        self::assertSame(42, $binds[':user_id']->getValue());
    }

    // ── andWhere / orWhere / xorWhere on top of WHERE ───────────────────────

    public function test_and_where_appends_AND_operator(): void
    {
        $sql = UsersRepo::instance()
            ->where(Qb::eq('a', new CDOBind('a_v', 1)))
            ->andWhere(Qb::eq('b', new CDOBind('b_v', 2)))
            ->buildSql();
        self::assertSame('SELECT * FROM users WHERE a = :a_v AND b = :b_v', $sql);
    }

    public function test_or_where_appends_OR_operator(): void
    {
        $sql = UsersRepo::instance()
            ->where(Qb::eq('a', new CDOBind('a_v', 1)))
            ->orWhere(Qb::eq('b', new CDOBind('b_v', 2)))
            ->buildSql();
        self::assertSame('SELECT * FROM users WHERE a = :a_v OR b = :b_v', $sql);
    }

    public function test_xor_where_appends_XOR_operator(): void
    {
        $sql = UsersRepo::instance()
            ->where(Qb::eq('a', new CDOBind('a_v', 1)))
            ->xorWhere(Qb::eq('b', new CDOBind('b_v', 2)))
            ->buildSql();
        self::assertSame('SELECT * FROM users WHERE a = :a_v XOR b = :b_v', $sql);
    }

    // ── andWhere with no prior WHERE seeds the clause ───────────────────────

    public function test_and_where_without_prior_where_seeds_the_clause(): void
    {
        $sql = UsersRepo::instance()
            ->andWhere(Qb::eq('a', new CDOBind('a_v', 1)))
            ->buildSql();
        self::assertSame('SELECT * FROM users WHERE a = :a_v', $sql);
    }

    public function test_and_where_with_empty_Qb_is_a_noop(): void
    {
        // empty Qb skipped; pre-existing where preserved unchanged.
        $r = UsersRepo::instance()
            ->where(Qb::eq('a', new CDOBind('a_v', 1)))
            ->andWhere(Qb::empty());
        self::assertSame('WHERE a = :a_v', $r->getSql('where'));
    }

    // ── Chained mix of operators ────────────────────────────────────────────

    public function test_chained_and_or_in_call_order(): void
    {
        $sql = UsersRepo::instance()
            ->where(Qb::eq('a', new CDOBind('a_v', 1)))
            ->andWhere(Qb::eq('b', new CDOBind('b_v', 2)))
            ->orWhere(Qb::eq('c', new CDOBind('c_v', 3)))
            ->buildSql();
        self::assertSame(
            'SELECT * FROM users WHERE a = :a_v AND b = :b_v OR c = :c_v',
            $sql,
        );
    }

    // ── Common Qb factories ─────────────────────────────────────────────────

    public function test_where_is_null(): void
    {
        $sql = UsersRepo::instance()->where(Qb::isNull('deleted_at'))->buildSql();
        self::assertSame('SELECT * FROM users WHERE deleted_at IS NULL', $sql);
    }

    public function test_where_in_list(): void
    {
        $sql = UsersRepo::instance()
            ->where(Qb::in('id', [1, 2, 3]))
            ->buildSql();
        // The Qb::in() form expands to id IN (:iqb?, :iqb?, :iqb?) — match shape only.
        self::assertMatchesRegularExpression(
            '/SELECT \* FROM users WHERE id IN \(:[\w]+, :[\w]+, :[\w]+\)/',
            $sql,
        );
    }
}
