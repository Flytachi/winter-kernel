<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Repository;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Kernel\Tests\Ppa\Repository\Fixtures\OrdersRepo;
use Flytachi\Winter\Kernel\Tests\Ppa\Repository\Fixtures\UsersRepo;
use PHPUnit\Framework\TestCase;

final class JoinBuilderTest extends TestCase
{
    // ── String form — table+alias passed verbatim, ON wrapped in parens ─────

    public function test_join_left_string_form(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinLeft('orders o', 'u.id = o.user_id')
            ->buildSql();
        self::assertSame('SELECT * FROM users u LEFT JOIN orders o ON(u.id = o.user_id)', $sql);
    }

    public function test_join_right_string_form(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinRight('orders o', 'u.id = o.user_id')
            ->buildSql();
        self::assertStringContainsString('RIGHT JOIN orders o ON(u.id = o.user_id)', $sql);
    }

    public function test_join_inner_string_form(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinInner('orders o', 'u.id = o.user_id')
            ->buildSql();
        self::assertStringContainsString('INNER JOIN orders o ON(u.id = o.user_id)', $sql);
    }

    public function test_plain_join_string_form(): void
    {
        $sql = UsersRepo::instance('u')
            ->join('orders o', 'u.id = o.user_id')
            ->buildSql();
        self::assertStringContainsString(' JOIN orders o ON(u.id = o.user_id)', $sql);
        self::assertStringNotContainsString('LEFT JOIN', $sql);
        self::assertStringNotContainsString('INNER JOIN', $sql);
    }

    // ── Cross join — no ON clause ────────────────────────────────────────────

    public function test_cross_join_string_form(): void
    {
        $sql = UsersRepo::instance('u')->joinCross('categories c')->buildSql();
        self::assertSame('SELECT * FROM users u CROSS JOIN categories c', $sql);
    }

    public function test_cross_join_with_repo_uses_originTable_plus_alias(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinCross(OrdersRepo::instance('o'))
            ->buildSql();
        self::assertSame('SELECT * FROM users u CROSS JOIN orders o', $sql);
    }

    // ── Repo form — simple repo with only alias renders as originTable alias ─

    public function test_join_left_with_repo_renders_origin_table_when_no_other_parts(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinLeft(OrdersRepo::instance('o'), 'u.id = o.user_id')
            ->buildSql();
        self::assertSame('SELECT * FROM users u LEFT JOIN orders o ON(u.id = o.user_id)', $sql);
    }

    // ── Repo form — repo with other parts (where/limit) becomes subquery ────

    public function test_join_with_filtered_repo_renders_as_subquery(): void
    {
        $subquery = OrdersRepo::instance('o')->where(Qb::eq('o.status', 'paid'));
        $sql = UsersRepo::instance('u')
            ->joinLeft($subquery, 'u.id = o.user_id')
            ->buildSql();
        // The aliased subquery is wrapped in parens, with the alias appearing
        // both inside the inner SELECT (as alias of orders) and after the parens.
        self::assertStringContainsString('LEFT JOIN (SELECT * FROM orders o WHERE o.status = :', $sql);
        self::assertStringContainsString(') o ON(u.id = o.user_id)', $sql);
    }

    // ── Multiple joins are chained ───────────────────────────────────────────

    public function test_multiple_joins_chained_in_call_order(): void
    {
        $sql = UsersRepo::instance('u')
            ->joinLeft('orders o', 'u.id = o.user_id')
            ->joinInner('payments p', 'p.order_id = o.id')
            ->buildSql();
        // Both LEFT JOIN and INNER JOIN appear, in chained order.
        self::assertStringContainsString('LEFT JOIN orders o ON(u.id = o.user_id) INNER JOIN payments p ON(p.order_id = o.id)', $sql);
    }

    // ── Qb-based ON propagates binds ────────────────────────────────────────

    public function test_join_with_Qb_on_propagates_binds(): void
    {
        $r = UsersRepo::instance('u')
            ->joinLeft('orders o', Qb::eq('o.user_id', 'u.id')); // contrived but exercises Qb path
        $sql = $r->buildSql();

        self::assertStringContainsString('LEFT JOIN orders o ON(o.user_id = :', $sql);

        $binds = $r->getSql('binds');
        self::assertIsArray($binds);
        self::assertNotEmpty($binds);
    }
}
