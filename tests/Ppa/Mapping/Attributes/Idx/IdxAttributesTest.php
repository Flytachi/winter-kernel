<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping\Attributes\Idx;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Index;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Primary;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Unique;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexMethod;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexType;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Index as IndexStructure;
use PHPUnit\Framework\TestCase;

final class IdxAttributesTest extends TestCase
{
    // ── Primary ──────────────────────────────────────────────────────────────

    public function test_primary_produces_primary_btree_structure(): void
    {
        $attr = new Primary();
        $attr->columnPreparation('id');
        $structure = $attr->toObject('mysql');

        self::assertInstanceOf(IndexStructure::class, $structure);
        self::assertSame(IndexType::PRIMARY, $structure->type);
        self::assertSame(IndexMethod::BTREE, $structure->method);
        self::assertSame(['id'], $structure->columns);
    }

    public function test_primary_column_preparation_unshifts_main_column_to_front(): void
    {
        $attr = new Primary();
        $attr->columnPreparation('id');
        // Calling twice with the same column does not duplicate.
        $attr->columnPreparation('id');
        $structure = $attr->toObject();
        self::assertSame(['id'], $structure->columns);
    }

    // ── Unique ───────────────────────────────────────────────────────────────

    public function test_unique_produces_unique_btree_structure_with_main_column(): void
    {
        $attr = new Unique();
        $attr->columnPreparation('email');
        $structure = $attr->toObject('pgsql');

        self::assertSame(IndexType::UNIQUE, $structure->type);
        self::assertSame(IndexMethod::BTREE, $structure->method);
        self::assertSame(['email'], $structure->columns);
    }

    public function test_unique_with_explicit_extra_columns_keeps_main_first(): void
    {
        $attr = new Unique(columns: ['tenant_id']);
        $attr->columnPreparation('email');
        $structure = $attr->toObject();
        self::assertSame(['email', 'tenant_id'], $structure->columns);
    }

    public function test_unique_passes_name_where_opclass_through(): void
    {
        $attr = new Unique(name: 'u_email', where: 'deleted_at IS NULL', opClass: 'text_pattern_ops');
        $attr->columnPreparation('email');
        $structure = $attr->toObject('pgsql');

        self::assertSame('u_email', $structure->name);
        self::assertSame('deleted_at IS NULL', $structure->where);
        self::assertSame('text_pattern_ops', $structure->opClass);
    }

    // ── Index ────────────────────────────────────────────────────────────────

    public function test_index_default_method_is_btree(): void
    {
        $attr = new Index();
        $attr->columnPreparation('user_id');
        $structure = $attr->toObject('mysql');

        self::assertSame(IndexType::INDEX, $structure->type);
        self::assertSame(IndexMethod::BTREE, $structure->method);
    }

    public function test_index_method_passes_through(): void
    {
        $attr = new Index(method: IndexMethod::GIN);
        $attr->columnPreparation('tags');
        self::assertSame(IndexMethod::GIN, $attr->toObject('pgsql')->method);
    }

    public function test_index_extra_columns_with_main_kept_first(): void
    {
        $attr = new Index(columns: ['country_id']);
        $attr->columnPreparation('user_id');
        self::assertSame(['user_id', 'country_id'], $attr->toObject()->columns);
    }
}
