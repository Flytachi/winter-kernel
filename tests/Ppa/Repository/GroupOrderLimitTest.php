<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Repository;

use Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures\UsersRepo;
use PHPUnit\Framework\TestCase;
use TypeError;

final class GroupOrderLimitTest extends TestCase
{
    // ── groupBy / having ────────────────────────────────────────────────────

    public function test_group_by_emits_GROUP_BY_clause(): void
    {
        $sql = UsersRepo::instance()->groupBy('status')->buildSql();
        self::assertSame('SELECT * FROM users GROUP BY status', $sql);
    }

    public function test_group_by_empty_is_a_noop(): void
    {
        $r = UsersRepo::instance()->groupBy('');
        self::assertNull($r->getSql('group'));
    }

    public function test_having_emits_HAVING_clause(): void
    {
        $sql = UsersRepo::instance()->groupBy('status')->having('COUNT(*) > 5')->buildSql();
        self::assertSame('SELECT * FROM users GROUP BY status HAVING COUNT(*) > 5', $sql);
    }

    public function test_having_empty_is_a_noop(): void
    {
        $r = UsersRepo::instance()->having('');
        self::assertNull($r->getSql('having'));
    }

    // ── orderBy ─────────────────────────────────────────────────────────────

    public function test_order_by_emits_ORDER_BY_clause(): void
    {
        $sql = UsersRepo::instance()->orderBy('id DESC')->buildSql();
        self::assertSame('SELECT * FROM users ORDER BY id DESC', $sql);
    }

    public function test_order_by_empty_is_a_noop(): void
    {
        $r = UsersRepo::instance()->orderBy('');
        self::assertNull($r->getSql('order'));
    }

    public function test_multi_column_order_by(): void
    {
        $sql = UsersRepo::instance()->orderBy('status ASC, id DESC')->buildSql();
        self::assertSame('SELECT * FROM users ORDER BY status ASC, id DESC', $sql);
    }

    // ── limit / offset ──────────────────────────────────────────────────────

    public function test_limit_only(): void
    {
        $sql = UsersRepo::instance()->limit(10)->buildSql();
        self::assertSame('SELECT * FROM users LIMIT 10', $sql);
    }

    public function test_limit_with_offset(): void
    {
        $sql = UsersRepo::instance()->limit(10, 5)->buildSql();
        self::assertSame('SELECT * FROM users LIMIT 10 OFFSET 5', $sql);
    }

    public function test_zero_limit_throws(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('limit < 1');
        UsersRepo::instance()->limit(0);
    }

    public function test_negative_offset_throws(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('offset < 0');
        UsersRepo::instance()->limit(10, -1);
    }

    public function test_offset_zero_is_omitted_from_output(): void
    {
        $sql = UsersRepo::instance()->limit(10, 0)->buildSql();
        self::assertSame('SELECT * FROM users LIMIT 10', $sql);
    }

    // ── forBy ───────────────────────────────────────────────────────────────

    public function test_for_by_appends_FOR_clause(): void
    {
        $sql = UsersRepo::instance()->limit(1)->forBy('UPDATE')->buildSql();
        self::assertSame('SELECT * FROM users LIMIT 1 FOR UPDATE', $sql);
    }

    // ── Combined ordering of GROUP / HAVING / ORDER / LIMIT ─────────────────

    public function test_full_aggregation_order(): void
    {
        $sql = UsersRepo::instance()
            ->groupBy('status')
            ->having('COUNT(*) > 5')
            ->orderBy('cnt DESC')
            ->limit(20, 10)
            ->buildSql();
        self::assertSame(
            'SELECT * FROM users GROUP BY status HAVING COUNT(*) > 5 ORDER BY cnt DESC LIMIT 20 OFFSET 10',
            $sql,
        );
    }
}
