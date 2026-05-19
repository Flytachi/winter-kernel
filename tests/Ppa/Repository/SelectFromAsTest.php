<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Repository;

use Flytachi\Winter\K2\Ppa\Repository\RepositoryException;
use Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures\OrdersRepo;
use Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures\TypedUsersRepo;
use Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures\UsersRepo;
use PHPUnit\Framework\TestCase;

final class SelectFromAsTest extends TestCase
{
    // ── instance() factory + as() alias ──────────────────────────────────────

    public function test_instance_without_alias_emits_bare_select_star(): void
    {
        self::assertSame('SELECT * FROM users', UsersRepo::instance()->buildSql());
    }

    public function test_instance_with_alias_emits_table_followed_by_alias_token(): void
    {
        self::assertSame('SELECT * FROM users u', UsersRepo::instance('u')->buildSql());
    }

    public function test_origin_table_returns_static_table(): void
    {
        self::assertSame('users', UsersRepo::instance()->originTable());
        self::assertSame('orders', OrdersRepo::instance()->originTable());
    }

    public function test_as_chain_sets_alias_part(): void
    {
        $r = UsersRepo::instance()->as('u');
        self::assertSame('u', $r->getSql('as'));
    }

    public function test_empty_alias_is_a_noop(): void
    {
        // as('') guard short-circuits — no alias part set.
        $r = UsersRepo::instance()->as('');
        self::assertNull($r->getSql('as'));
    }

    // ── select() — option overrides default column list ──────────────────────

    public function test_select_option_overrides_star_in_buildSql(): void
    {
        $sql = UsersRepo::instance()->select('id, email')->buildSql();
        self::assertSame('SELECT id, email FROM users', $sql);
    }

    public function test_empty_select_option_is_ignored(): void
    {
        // select('') is a noop — falls back to default '*' rendering.
        $sql = UsersRepo::instance()->select('')->buildSql();
        self::assertSame('SELECT * FROM users', $sql);
    }

    public function test_select_option_persists_in_getSql_option_part(): void
    {
        $r = UsersRepo::instance()->select('COUNT(*) AS total');
        self::assertSame('COUNT(*) AS total', $r->getSql('option'));
    }

    // ── Typed entity — buildSql produces explicit column list ────────────────

    public function test_typed_entity_emits_public_properties_as_select_columns(): void
    {
        self::assertSame(
            'SELECT id, email, name FROM users',
            TypedUsersRepo::instance()->buildSql(),
        );
    }

    public function test_typed_entity_with_alias_prefixes_columns(): void
    {
        self::assertSame(
            'SELECT u.id, u.email, u.name FROM users u',
            TypedUsersRepo::instance('u')->buildSql(),
        );
    }

    public function test_select_option_resets_entity_back_to_stdClass(): void
    {
        // When select() is used, the entity is downgraded to stdClass —
        // hydration switches to free-form rows. Documented in prepareSelect().
        $r = TypedUsersRepo::instance('u')->select('COUNT(*)');
        $r->buildSql(); // forces evaluation
        self::assertSame(\stdClass::class, $r->getEntityClassName());
    }

    // ── from() — string source overrides default origin table ───────────────

    public function test_from_string_overrides_origin_table(): void
    {
        $sql = UsersRepo::instance()->from('public.users')->buildSql();
        self::assertSame('SELECT * FROM public.users', $sql);
    }

    public function test_from_called_twice_throws(): void
    {
        $r = UsersRepo::instance()->from('public.users');
        $this->expectException(RepositoryException::class);
        $r->from('audit.users');
    }

    public function test_from_with_repository_requires_alias_first(): void
    {
        $this->expectException(RepositoryException::class);
        $this->expectExceptionMessageMatches('/FROM subquery requires an alias/');
        UsersRepo::instance()->from(OrdersRepo::instance());
    }

    public function test_from_with_repository_renders_as_subquery_when_alias_set(): void
    {
        $sql = UsersRepo::instance()
            ->as('u')
            ->from(OrdersRepo::instance())
            ->buildSql();
        self::assertSame('SELECT * FROM (SELECT * FROM orders) u', $sql);
    }
}
