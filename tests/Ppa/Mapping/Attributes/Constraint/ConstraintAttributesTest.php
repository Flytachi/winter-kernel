<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping\Attributes\Constraint;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\Check;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\CheckEnum;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\ForeignKey;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\ForeignRepo;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\FKAction;
use Flytachi\Winter\K2\Ppa\Mapping\RepositoryMappingInterface;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\CheckConstraint;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\ForeignKey as ForeignKeyStructure;
use PHPUnit\Framework\TestCase;

// ── Enums used by CheckEnum tests ────────────────────────────────────────────

enum StringStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}

enum IntPriority: int
{
    case Low = 1;
    case High = 10;
}

enum PlainEnum
{
    case A;
    case B;
}

// ── Stub repo used by ForeignRepo tests ──────────────────────────────────────

final class StubRepoForFkTest implements RepositoryMappingInterface
{
    public function originTable(): string
    {
        return 'public.users';
    }

    public function mapIdentifierColumnName(): string
    {
        return 'id';
    }
}

final class ConstraintAttributesTest extends TestCase
{
    // ── Check — straight pass-through ────────────────────────────────────────

    public function test_check_produces_check_constraint_structure_with_expression(): void
    {
        $struct = (new Check('age >= 0'))->toObject('age');
        self::assertInstanceOf(CheckConstraint::class, $struct);
        self::assertSame('age >= 0', $struct->expression);
        self::assertNull($struct->name);
    }

    public function test_check_propagates_name(): void
    {
        $struct = (new Check('age >= 0', name: 'chk_age'))->toObject('age');
        self::assertSame('chk_age', $struct->name);
    }

    // ── CheckEnum — produces "col IN (...)" expression ───────────────────────

    public function test_check_enum_with_string_backed_enum_quotes_values(): void
    {
        $struct = (new CheckEnum(StringStatus::class))->toObject('status');
        self::assertSame(
            "status IN ('active', 'inactive', 'pending')",
            $struct->expression,
        );
    }

    public function test_check_enum_with_int_backed_enum_leaves_values_bare(): void
    {
        $struct = (new CheckEnum(IntPriority::class))->toObject('priority');
        self::assertSame('priority IN (1, 10)', $struct->expression);
    }

    public function test_check_enum_constructor_rejects_non_enum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a valid enum/');
        new CheckEnum(self::class);
    }

    public function test_check_enum_constructor_rejects_non_backed_enum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a Backed Enum/');
        new CheckEnum(PlainEnum::class);
    }

    // ── ForeignKey attribute — toObject pushes columnName through ────────────

    public function test_foreign_key_attribute_propagates_columnName_to_structure(): void
    {
        // Regression coverage for the earlier quirk fix in Constraint/ForeignKey::toObject.
        $struct = (new ForeignKey(
            referencedTable: 'users',
            referencedColumn: 'id',
            name: 'fk_orders_user',
        ))->toObject('user_id', 'pgsql');

        self::assertInstanceOf(ForeignKeyStructure::class, $struct);
        self::assertSame('users', $struct->referencedTable);
        self::assertSame('id', $struct->referencedColumn);
        self::assertSame('user_id', $struct->columnName);
        self::assertSame('fk_orders_user', $struct->name);
    }

    public function test_foreign_key_attribute_default_actions_are_restrict(): void
    {
        $struct = (new ForeignKey('users', 'id'))->toObject('user_id');
        self::assertSame(FKAction::RESTRICT, $struct->onUpdate);
        self::assertSame(FKAction::RESTRICT, $struct->onDelete);
    }

    public function test_foreign_key_attribute_actions_propagate(): void
    {
        $struct = (new ForeignKey(
            referencedTable: 'users',
            referencedColumn: 'id',
            onUpdate: FKAction::CASCADE,
            onDelete: FKAction::SET_NULL,
        ))->toObject('user_id');

        self::assertSame(FKAction::CASCADE, $struct->onUpdate);
        self::assertSame(FKAction::SET_NULL, $struct->onDelete);
    }

    // ── ForeignRepo — resolves table/column from RepositoryMappingInterface ──

    public function test_foreign_repo_resolves_referenced_table_and_column_from_repo(): void
    {
        $struct = (new ForeignRepo(StubRepoForFkTest::class))->toObject('user_id', 'pgsql');

        self::assertSame('public.users', $struct->referencedTable);
        self::assertSame('id', $struct->referencedColumn);
        self::assertSame('user_id', $struct->columnName); // regression: now propagated
    }

    public function test_foreign_repo_propagates_actions_and_name(): void
    {
        $struct = (new ForeignRepo(
            referencedRepoClass: StubRepoForFkTest::class,
            onUpdate: FKAction::CASCADE,
            onDelete: FKAction::SET_NULL,
            name: 'fk_orders_user',
        ))->toObject('user_id');

        self::assertSame(FKAction::CASCADE, $struct->onUpdate);
        self::assertSame(FKAction::SET_NULL, $struct->onDelete);
        self::assertSame('fk_orders_user', $struct->name);
    }

    public function test_foreign_repo_rejects_non_repo_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must implement DbMapRepoInterface/');
        (new ForeignRepo(\stdClass::class))->toObject('user_id');
    }
}
