<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Repository;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures\OrdersRepo;
use Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures\UsersRepo;
use PHPUnit\Framework\TestCase;

final class UnionCteTest extends TestCase
{
    // ── UNION / UNION ALL ───────────────────────────────────────────────────

    public function test_union_appends_keyword_and_inner_sql(): void
    {
        $sql = UsersRepo::instance()->union(OrdersRepo::instance())->buildSql();
        self::assertSame('SELECT * FROM users UNION SELECT * FROM orders', $sql);
    }

    public function test_union_all_appends_keyword_with_ALL(): void
    {
        $sql = UsersRepo::instance()->unionAll(OrdersRepo::instance())->buildSql();
        self::assertSame('SELECT * FROM users UNION ALL SELECT * FROM orders', $sql);
    }

    public function test_chained_unions_keep_order(): void
    {
        $sql = UsersRepo::instance()
            ->union(OrdersRepo::instance())
            ->unionAll(UsersRepo::instance())
            ->buildSql();
        self::assertSame(
            'SELECT * FROM users UNION SELECT * FROM orders UNION ALL SELECT * FROM users',
            $sql,
        );
    }

    public function test_union_inherits_inner_repo_binds(): void
    {
        $sub = OrdersRepo::instance()->where(Qb::eq('status', new CDOBind('s', 'paid')));
        $r = UsersRepo::instance()->union($sub);

        $binds = $r->getSql('binds');
        self::assertArrayHasKey(':s', $binds);
        self::assertSame('paid', $binds[':s']->getValue());
    }

    // ── WITH / WITH RECURSIVE ───────────────────────────────────────────────

    public function test_with_prepends_WITH_clause(): void
    {
        $sql = UsersRepo::instance()
            ->with('orders_cte', OrdersRepo::instance())
            ->buildSql();
        self::assertSame(
            'WITH orders_cte AS (SELECT * FROM orders) SELECT * FROM users',
            $sql,
        );
    }

    public function test_with_modifier_renders_after_AS(): void
    {
        $sql = UsersRepo::instance()
            ->with('orders_cte', OrdersRepo::instance(), 'MATERIALIZED')
            ->buildSql();
        self::assertSame(
            'WITH orders_cte AS MATERIALIZED (SELECT * FROM orders) SELECT * FROM users',
            $sql,
        );
    }

    public function test_multiple_with_joined_with_comma(): void
    {
        $sql = UsersRepo::instance()
            ->with('a', OrdersRepo::instance())
            ->with('b', UsersRepo::instance())
            ->buildSql();
        self::assertSame(
            'WITH a AS (SELECT * FROM orders), b AS (SELECT * FROM users) SELECT * FROM users',
            $sql,
        );
    }

    public function test_with_recursive_swaps_keyword(): void
    {
        $sql = UsersRepo::instance()
            ->withRecursive('tree', OrdersRepo::instance())
            ->buildSql();
        self::assertStringStartsWith(
            'WITH RECURSIVE tree AS (SELECT * FROM orders)',
            $sql,
        );
    }

    public function test_with_inherits_inner_repo_binds(): void
    {
        $sub = OrdersRepo::instance()->where(Qb::eq('status', new CDOBind('s', 'paid')));
        $r = UsersRepo::instance()->with('paid_orders', $sub);

        $binds = $r->getSql('binds');
        self::assertArrayHasKey(':s', $binds);
    }
}
