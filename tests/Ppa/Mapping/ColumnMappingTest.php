<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Additive\DefaultVal;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Additive\NullableIs;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\Check;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\ForeignKey as ForeignKeyAttr;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Index as IndexAttr;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Unique;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Integer;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Varchar;
use Flytachi\Winter\K2\Ppa\Mapping\ColumnMapping;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexType;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Column;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

// ── Fixture entities ─────────────────────────────────────────────────────────

final class CmSimpleEntity
{
    public int $id;
    public string $email;
    public ?string $note = null;
}

final class CmTypedEntity
{
    #[Integer]
    public int $count;

    #[Varchar(50)]
    public string $code;
}

final class CmDefaultsEntity
{
    public bool $active = true;
    public bool $deleted = false;
    public int $score = 42;
    public string $status = 'pending';
    public ?string $note = null;

    #[DefaultVal('CURRENT_TIMESTAMP')]
    public string $createdAt;

    #[NullableIs(false)]
    public ?string $forcedNotNull = null;
}

final class CmIdEntity
{
    #[Id]
    public int $id;
}

final class CmConstraintsEntity
{
    #[Integer]
    #[Unique]
    public int $sku;

    #[Integer]
    #[IndexAttr]
    public int $category_id;

    #[Integer]
    #[ForeignKeyAttr(referencedTable: 'users', referencedColumn: 'id', name: 'fk_orders_user')]
    public int $owner_id;

    #[Integer]
    #[Check('age >= 0')]
    public int $age;
}

final class CmTypeMismatchEntity
{
    #[Integer]
    public string $not_an_int;
}

final class CmDuplicateTypeEntity
{
    #[Integer]
    #[Varchar(255)]
    public int $oops;
}

// ── Tests ────────────────────────────────────────────────────────────────────

final class ColumnMappingTest extends TestCase
{
    private function mapProperty(string $class, string $prop, string $dialect = 'mysql'): Column
    {
        $mapping = new ColumnMapping($dialect);
        $mapping->push(new ReflectionProperty($class, $prop));
        $cols = $mapping->getColumns();
        self::assertCount(1, $cols);
        return $cols[0];
    }

    // ── Type inference from PHP types ───────────────────────────────────────

    public function test_int_property_without_attribute_becomes_INT(): void
    {
        $col = $this->mapProperty(CmSimpleEntity::class, 'id', 'mysql');
        self::assertSame('id', $col->name);
        self::assertSame('INT', $col->type);
        self::assertFalse($col->nullable);
    }

    public function test_string_property_without_attribute_becomes_varchar_255(): void
    {
        $col = $this->mapProperty(CmSimpleEntity::class, 'email', 'mysql');
        self::assertSame('VARCHAR(255)', $col->type);
        self::assertFalse($col->nullable);
    }

    public function test_nullable_string_property_is_marked_nullable(): void
    {
        $col = $this->mapProperty(CmSimpleEntity::class, 'note', 'mysql');
        self::assertSame('VARCHAR(255)', $col->type);
        self::assertTrue($col->nullable);
    }

    // ── Explicit AttributeDbType overrides inferred type ─────────────────────

    public function test_integer_attribute_renders_int(): void
    {
        $col = $this->mapProperty(CmTypedEntity::class, 'count', 'mysql');
        self::assertSame('INT', $col->type);
    }

    public function test_varchar_attribute_with_custom_length(): void
    {
        $col = $this->mapProperty(CmTypedEntity::class, 'code', 'mysql');
        self::assertSame('VARCHAR(50)', $col->type);
    }

    // ── Defaults from PHP property defaults ─────────────────────────────────

    public function test_bool_true_default_renders_as_TRUE(): void
    {
        $col = $this->mapProperty(CmDefaultsEntity::class, 'active');
        self::assertSame('TRUE', $col->default);
    }

    public function test_bool_false_default_renders_as_FALSE(): void
    {
        $col = $this->mapProperty(CmDefaultsEntity::class, 'deleted');
        self::assertSame('FALSE', $col->default);
    }

    public function test_int_default_renders_as_int_literal(): void
    {
        $col = $this->mapProperty(CmDefaultsEntity::class, 'score');
        self::assertSame('42', $col->default);
    }

    public function test_string_default_renders_quoted(): void
    {
        $col = $this->mapProperty(CmDefaultsEntity::class, 'status');
        self::assertSame("'pending'", $col->default);
    }

    public function test_nullable_string_default_null_renders_as_null_token(): void
    {
        $col = $this->mapProperty(CmDefaultsEntity::class, 'note');
        self::assertSame('NULL', $col->default);
    }

    // ── #[DefaultVal] overrides PHP default ─────────────────────────────────

    public function test_default_val_attribute_takes_precedence(): void
    {
        $col = $this->mapProperty(CmDefaultsEntity::class, 'createdAt');
        self::assertSame('CURRENT_TIMESTAMP', $col->default);
    }

    // ── #[NullableIs] overrides PHP type nullability ────────────────────────

    public function test_nullable_is_false_overrides_php_nullable_type(): void
    {
        $col = $this->mapProperty(CmDefaultsEntity::class, 'forcedNotNull');
        self::assertFalse($col->nullable);
    }

    // ── Hybrid attribute expansion (#[Id]) ──────────────────────────────────

    public function test_id_hybrid_expands_to_integer_auto_increment_not_null_primary_mysql(): void
    {
        $col = $this->mapProperty(CmIdEntity::class, 'id', 'mysql');
        self::assertSame('id', $col->name);
        self::assertSame('INT AUTO_INCREMENT', $col->type);
        self::assertFalse($col->nullable);
        self::assertCount(1, $col->indexes);
        self::assertSame(IndexType::PRIMARY, $col->indexes[0]->type);
    }

    public function test_id_hybrid_renders_identity_form_on_pgsql(): void
    {
        $col = $this->mapProperty(CmIdEntity::class, 'id', 'pgsql');
        self::assertSame('INT GENERATED BY DEFAULT AS IDENTITY', $col->type);
    }

    // ── Constraints attached via attributes ─────────────────────────────────

    public function test_unique_index_attribute_attached(): void
    {
        $col = $this->mapProperty(CmConstraintsEntity::class, 'sku');
        self::assertCount(1, $col->indexes);
        self::assertSame(IndexType::UNIQUE, $col->indexes[0]->type);
    }

    public function test_plain_index_attribute_attached(): void
    {
        $col = $this->mapProperty(CmConstraintsEntity::class, 'category_id');
        self::assertCount(1, $col->indexes);
        self::assertSame(IndexType::INDEX, $col->indexes[0]->type);
    }

    public function test_foreign_key_attribute_attached_with_column_name_pushed_through(): void
    {
        $col = $this->mapProperty(CmConstraintsEntity::class, 'owner_id');
        self::assertNotNull($col->foreignKey);
        self::assertSame('users', $col->foreignKey->referencedTable);
        self::assertSame('id', $col->foreignKey->referencedColumn);
        // After quirk fix, the FK structure carries the local column name.
        self::assertSame('owner_id', $col->foreignKey->columnName);
        self::assertSame('fk_orders_user', $col->foreignKey->name);
    }

    public function test_check_attribute_attached(): void
    {
        $col = $this->mapProperty(CmConstraintsEntity::class, 'age');
        self::assertNotNull($col->checkConstraint);
        self::assertSame('age >= 0', $col->checkConstraint->expression);
    }

    // ── Error paths ─────────────────────────────────────────────────────────

    public function test_integer_attribute_on_string_property_throws_unsupported_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not support this type/');
        $this->mapProperty(CmTypeMismatchEntity::class, 'not_an_int');
    }

    public function test_two_attribute_db_types_on_one_property_throws_already_set(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/is already set to/');
        $this->mapProperty(CmDuplicateTypeEntity::class, 'oops');
    }

    // ── Accumulation across multiple push() calls ───────────────────────────

    public function test_get_columns_accumulates_pushed_properties_in_order(): void
    {
        $m = new ColumnMapping('mysql');
        $m->push(new ReflectionProperty(CmSimpleEntity::class, 'id'));
        $m->push(new ReflectionProperty(CmSimpleEntity::class, 'email'));
        $m->push(new ReflectionProperty(CmSimpleEntity::class, 'note'));

        $names = array_map(static fn (Column $c) => $c->name, $m->getColumns());
        self::assertSame(['id', 'email', 'note'], $names);
    }
}
