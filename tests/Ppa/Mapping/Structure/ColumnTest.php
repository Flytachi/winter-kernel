<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Mapping\Structure;

use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\IndexType;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\CheckConstraint;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Column;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\ForeignKey;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Index;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\StructureInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    public function test_implements_structure_interface(): void
    {
        self::assertInstanceOf(StructureInterface::class, new Column('id', 'INT'));
    }

    // ── toSql() — column definition ──────────────────────────────────────────

    public function test_nullable_by_default_no_not_null_token(): void
    {
        $sql = (new Column('email', 'VARCHAR(255)'))->toSql('users');
        self::assertSame('email VARCHAR(255)', $sql);
    }

    public function test_not_null_when_nullable_false(): void
    {
        $sql = (new Column('email', 'VARCHAR(255)', nullable: false))->toSql('users');
        self::assertSame('email VARCHAR(255) NOT NULL', $sql);
    }

    public function test_default_value_appended_when_provided(): void
    {
        $sql = (new Column('status', 'VARCHAR(16)', nullable: false, default: "'active'"))->toSql('users');
        self::assertSame("status VARCHAR(16) NOT NULL DEFAULT 'active'", $sql);
    }

    public function test_default_with_nullable_true(): void
    {
        $sql = (new Column('flag', 'BOOLEAN', default: 'false'))->toSql('users');
        self::assertSame('flag BOOLEAN DEFAULT false', $sql);
    }

    public function test_default_zero_string_is_emitted(): void
    {
        // current behaviour: default !== null check, so '0' is kept
        $sql = (new Column('cnt', 'INT', default: '0'))->toSql('users');
        self::assertSame('cnt INT DEFAULT 0', $sql);
    }

    public function test_invalid_name_throws_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Column('bad-col', 'INT');
    }

    // ── constraintsSql() — delegates to attached structures ─────────────────

    public function test_constraints_empty_when_nothing_attached(): void
    {
        self::assertSame([], (new Column('id', 'INT'))->constraintsSql('users'));
    }

    public function test_constraints_include_attached_index(): void
    {
        $idx = new Index(columns: ['email'], type: IndexType::UNIQUE);
        $col = new Column('email', 'VARCHAR(255)', indexes: [$idx]);

        $constraints = $col->constraintsSql('users', 'pgsql');
        self::assertCount(1, $constraints);
        self::assertStringContainsString('CREATE UNIQUE INDEX', $constraints[0]);
    }

    public function test_constraints_include_attached_foreign_key(): void
    {
        $fk = new ForeignKey('users', 'id');
        $col = new Column('user_id', 'INT', foreignKey: $fk);

        $constraints = $col->constraintsSql('orders');
        self::assertCount(1, $constraints);
        self::assertStringContainsString('FOREIGN KEY (user_id)', $constraints[0]);
        self::assertStringContainsString('REFERENCES users(id)', $constraints[0]);
    }

    public function test_constraints_include_attached_check(): void
    {
        $check = new CheckConstraint('age >= 0', name: 'chk_positive');
        $col = new Column('age', 'INT', checkConstraint: $check);

        $constraints = $col->constraintsSql('users');
        self::assertCount(1, $constraints);
        self::assertSame(
            'ALTER TABLE users ADD CONSTRAINT chk_positive CHECK (age >= 0)',
            $constraints[0],
        );
    }

    public function test_constraints_combined_in_declaration_order(): void
    {
        $idx = new Index(columns: ['email'], type: IndexType::UNIQUE, name: 'u_email');
        $fk = new ForeignKey('roles', 'id', name: 'fk_role');
        $check = new CheckConstraint('LENGTH(email) > 3', name: 'chk_email_len');

        $col = new Column(
            name: 'email',
            type: 'VARCHAR(255)',
            indexes: [$idx],
            foreignKey: $fk,
            checkConstraint: $check,
        );

        $constraints = $col->constraintsSql('users', 'pgsql');
        self::assertCount(3, $constraints);
        // order: indexes → foreignKey → check
        self::assertStringContainsString('CREATE UNIQUE INDEX', $constraints[0]);
        self::assertStringContainsString('ADD CONSTRAINT fk_role', $constraints[1]);
        self::assertStringContainsString('ADD CONSTRAINT chk_email_len', $constraints[2]);
    }

    // ── Column::getPrimitiveSqlType() — static PHP→SQL type mapper ─────────

    /** @return array<string, array{0: array<string>, 1: string, 2: string}> */
    public static function singleTypes(): array
    {
        return [
            'bool'   => [['bool'],   'mysql', 'BOOLEAN'],
            'int'    => [['int'],    'mysql', 'INT'],
            'string' => [['string'], 'mysql', 'VARCHAR(255)'],
            'float-mysql' => [['float'], 'mysql', 'FLOAT'],
            'float-pgsql' => [['float'], 'pgsql', 'REAL'],
            'array → TEXT fallback' => [['array'], 'mysql', 'TEXT'],
        ];
    }

    #[DataProvider('singleTypes')]
    public function test_primitive_type_single(array $types, string $dialect, string $expected): void
    {
        self::assertSame($expected, Column::getPrimitiveSqlType($types, $dialect));
    }

    public function test_primitive_type_strips_null(): void
    {
        // 'null' is filtered out, so ['int', 'null'] is treated as just ['int'].
        self::assertSame('INT', Column::getPrimitiveSqlType(['int', 'null']));
    }

    public function test_primitive_int_or_float_mysql_is_double(): void
    {
        self::assertSame('DOUBLE', Column::getPrimitiveSqlType(['int', 'float'], 'mysql'));
    }

    public function test_primitive_int_or_float_pgsql_is_double_precision(): void
    {
        self::assertSame('DOUBLE PRECISION', Column::getPrimitiveSqlType(['int', 'float'], 'pgsql'));
    }

    public function test_primitive_int_or_string_is_text(): void
    {
        self::assertSame('TEXT', Column::getPrimitiveSqlType(['int', 'string']));
    }

    public function test_primitive_bool_or_int_is_int(): void
    {
        self::assertSame('INT', Column::getPrimitiveSqlType(['bool', 'int']));
    }

    public function test_primitive_unhandled_combination_falls_back_to_text(): void
    {
        self::assertSame('TEXT', Column::getPrimitiveSqlType(['bool', 'string']));
    }

    public function test_primitive_empty_after_null_filter_is_text(): void
    {
        self::assertSame('TEXT', Column::getPrimitiveSqlType(['null']));
    }

    public function test_primitive_unknown_dialect_for_float_falls_back_to_text(): void
    {
        self::assertSame('TEXT', Column::getPrimitiveSqlType(['float'], 'sqlite'));
    }
}
