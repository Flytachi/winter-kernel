<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Repository;

use Flytachi\Winter\Kernel\Tests\Ppa\Repository\Fixtures\SelectionMappedRepo;
use Flytachi\Winter\Kernel\Tests\Ppa\Repository\Fixtures\TypedUsersRepo;
use PHPUnit\Framework\TestCase;

final class PrepareSelectTest extends TestCase
{
    // ── stdClass entity → SELECT * ──────────────────────────────────────────

    public function test_stdClass_entity_emits_select_star(): void
    {
        // UsersRepo uses default stdClass entity → '*' in SELECT.
        $r = \Flytachi\Winter\Kernel\Tests\Ppa\Repository\Fixtures\UsersRepo::instance();
        self::assertStringStartsWith('SELECT * FROM ', $r->buildSql());
    }

    // ── Typed entity → column list from public properties ───────────────────

    public function test_typed_entity_emits_property_names_without_alias(): void
    {
        self::assertSame('SELECT id, email, name FROM users', TypedUsersRepo::instance()->buildSql());
    }

    public function test_typed_entity_with_alias_prefixes_every_column(): void
    {
        self::assertSame(
            'SELECT u.id, u.email, u.name FROM users u',
            TypedUsersRepo::instance('u')->buildSql(),
        );
    }

    // ── EntityInterface::selection() — per-column SQL override ──────────────

    public function test_selection_map_overrides_mapped_property_only(): void
    {
        $sql = SelectionMappedRepo::instance('u')->buildSql();

        // Unmapped properties (id, email) get the alias prefix.
        self::assertStringContainsString('u.id', $sql);
        self::assertStringContainsString('u.email', $sql);

        // Mapped property uses the raw expression verbatim — no alias prefix.
        self::assertStringContainsString("CONCAT(first_name, ' ', last_name) AS fullName", $sql);
        self::assertStringNotContainsString('u.fullName', $sql);
    }

    public function test_selection_map_works_without_alias(): void
    {
        $sql = SelectionMappedRepo::instance()->buildSql();
        self::assertStringContainsString("CONCAT(first_name, ' ', last_name) AS fullName", $sql);
        // Unmapped properties have no prefix.
        self::assertMatchesRegularExpression('/SELECT id, email, /', $sql);
    }
}
