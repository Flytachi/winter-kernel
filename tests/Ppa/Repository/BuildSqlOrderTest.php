<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Repository;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures\OrdersRepo;
use Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures\UsersRepo;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the final clause assembly order in buildSql():
 *   WITH → SELECT → FROM → alias → JOIN → WHERE → GROUP → HAVING →
 *   UNION → ORDER → LIMIT → OFFSET → FOR
 */
final class BuildSqlOrderTest extends TestCase
{
    public function test_full_clause_order_is_canonical(): void
    {
        $sql = UsersRepo::instance('u')
            ->with('cte', OrdersRepo::instance())
            ->select('u.id, u.email')
            ->joinLeft('orders o', 'u.id = o.user_id')
            ->where(Qb::eq('u.status', new CDOBind('st', 'active')))
            ->groupBy('u.id')
            ->having('COUNT(o.id) > 0')
            ->orderBy('u.id DESC')
            ->limit(20, 5)
            ->forBy('UPDATE')
            ->buildSql();

        self::assertSame(
            'WITH cte AS (SELECT * FROM orders) '
            . 'SELECT u.id, u.email FROM users u LEFT JOIN orders o ON(u.id = o.user_id) '
            . 'WHERE u.status = :st GROUP BY u.id HAVING COUNT(o.id) > 0 '
            . 'ORDER BY u.id DESC LIMIT 20 OFFSET 5 FOR UPDATE',
            $sql,
        );
    }

    public function test_clauses_appear_in_canonical_positions_via_offsets(): void
    {
        $sql = UsersRepo::instance('u')
            ->with('cte', OrdersRepo::instance())
            ->joinLeft('orders o', 'u.id = o.user_id')
            ->where(Qb::eq('u.status', new CDOBind('st', 'active')))
            ->groupBy('u.id')
            ->having('COUNT(*) > 0')
            ->orderBy('u.id')
            ->limit(10)
            ->buildSql();

        $with = strpos($sql, 'WITH');
        $select = strpos($sql, 'SELECT');
        $from = strpos($sql, ' FROM ');
        $join = strpos($sql, 'LEFT JOIN');
        $where = strpos($sql, 'WHERE');
        $group = strpos($sql, 'GROUP BY');
        $having = strpos($sql, 'HAVING');
        $order = strpos($sql, 'ORDER BY');
        $limit = strpos($sql, 'LIMIT');

        self::assertLessThan($select, $with);
        self::assertLessThan($from, $select);
        self::assertLessThan($join, $from);
        self::assertLessThan($where, $join);
        self::assertLessThan($group, $where);
        self::assertLessThan($having, $group);
        self::assertLessThan($order, $having);
        self::assertLessThan($limit, $order);
    }

    public function test_union_appears_between_having_and_order(): void
    {
        $sql = UsersRepo::instance()
            ->groupBy('id')
            ->having('COUNT(*) > 0')
            ->union(OrdersRepo::instance())
            ->orderBy('id')
            ->buildSql();

        $having = strpos($sql, 'HAVING');
        $union = strpos($sql, 'UNION');
        $order = strpos($sql, 'ORDER BY');

        self::assertLessThan($union, $having);
        self::assertLessThan($order, $union);
    }

    public function test_buildSql_omits_skipped_clauses_cleanly(): void
    {
        $sql = UsersRepo::instance()->orderBy('id')->buildSql();
        self::assertSame('SELECT * FROM users ORDER BY id', $sql);

        self::assertStringNotContainsString('WHERE', $sql);
        self::assertStringNotContainsString('GROUP BY', $sql);
        self::assertStringNotContainsString('HAVING', $sql);
        self::assertStringNotContainsString('LIMIT', $sql);
        self::assertStringNotContainsString('OFFSET', $sql);
    }

    public function test_getSql_without_arg_returns_buildSql_output(): void
    {
        $r = UsersRepo::instance()->orderBy('id');
        self::assertSame($r->buildSql(), $r->getSql());
    }

    public function test_getSql_with_unknown_key_returns_null(): void
    {
        self::assertNull(UsersRepo::instance()->getSql('nonexistent'));
    }
}
