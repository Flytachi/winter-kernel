<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Mapping\Structure;

use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\IndexType;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\CheckConstraint;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Column;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\ForeignKey;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Index;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\StructureInterface;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Table;
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
    public function test_implements_structure_interface(): void
    {
        self::assertInstanceOf(StructureInterface::class, new Table('users', columns: []));
    }

    public function test_invalid_table_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Table('bad-table', columns: []);
    }

    public function test_invalid_schema_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Table('users', columns: [], schema: 'bad-schema');
    }

    // ── getFullName / createSchemaIfNotExists ────────────────────────────────

    public function test_full_name_without_schema(): void
    {
        self::assertSame('users', (new Table('users', columns: []))->getFullName());
    }

    public function test_full_name_with_schema(): void
    {
        self::assertSame('public.users', (new Table('users', columns: [], schema: 'public'))->getFullName());
    }

    public function test_create_schema_returns_null_when_no_schema(): void
    {
        self::assertNull((new Table('users', columns: []))->createSchemaIfNotExists('pgsql'));
    }

    public function test_create_schema_emits_only_for_pgsql(): void
    {
        $table = new Table('users', columns: [], schema: 'app');
        self::assertSame('CREATE SCHEMA app;', $table->createSchemaIfNotExists('pgsql'));
        self::assertNull($table->createSchemaIfNotExists('mysql'));
        self::assertNull($table->createSchemaIfNotExists('sqlite'));
    }

    // ── toSql() — minimal ────────────────────────────────────────────────────

    public function test_empty_table_renders_empty_create_block(): void
    {
        $sql = (new Table('empty', columns: []))->toSql('pgsql');
        self::assertSame("CREATE TABLE empty (\n\n);", $sql);
    }

    public function test_single_column_pgsql(): void
    {
        $sql = (new Table('users', columns: [
            new Column('id', 'INT', nullable: false),
        ]))->toSql('pgsql');

        self::assertSame("CREATE TABLE users (\n  id INT NOT NULL\n);", $sql);
    }

    public function test_schema_qualified_table_renders_with_schema_prefix(): void
    {
        $sql = (new Table('users', columns: [
            new Column('id', 'INT'),
        ], schema: 'public'))->toSql('pgsql');

        self::assertStringStartsWith("CREATE TABLE public.users (", $sql);
    }

    // ── Primary key merging from column-level indexes ────────────────────────

    public function test_single_column_primary_key_appears_inside_table_body(): void
    {
        $sql = (new Table('users', columns: [
            new Column('id', 'INT', nullable: false, indexes: [
                new Index(columns: ['id'], type: IndexType::PRIMARY),
            ]),
        ]))->toSql('pgsql');

        self::assertStringContainsString('PRIMARY KEY (id)', $sql);
        // not as external CREATE INDEX
        self::assertStringNotContainsString('CREATE INDEX', $sql);
    }

    public function test_composite_primary_key_merged_from_multiple_columns(): void
    {
        $sql = (new Table('user_role', columns: [
            new Column('user_id', 'INT', nullable: false, indexes: [
                new Index(columns: ['user_id'], type: IndexType::PRIMARY),
            ]),
            new Column('role_id', 'INT', nullable: false, indexes: [
                new Index(columns: ['role_id'], type: IndexType::PRIMARY),
            ]),
        ]))->toSql('pgsql');

        // Both PRIMARY KEY clauses get merged into a single composite line.
        self::assertStringContainsString('PRIMARY KEY (user_id, role_id)', $sql);
        self::assertSame(1, substr_count($sql, 'PRIMARY KEY'));
    }

    // ── External statements (CREATE INDEX, ALTER TABLE constraints) ──────────

    public function test_column_unique_index_emitted_as_external_create_statement(): void
    {
        $sql = (new Table('users', columns: [
            new Column('email', 'VARCHAR(255)', nullable: false, indexes: [
                new Index(columns: ['email'], type: IndexType::UNIQUE),
            ]),
        ]))->toSql('pgsql');

        self::assertStringContainsString("CREATE UNIQUE INDEX users_email_udx", $sql);
        // External statement appears after the CREATE TABLE block.
        $createTableEnd = strpos($sql, ");") + 2;
        $externalPart = substr($sql, $createTableEnd);
        self::assertStringContainsString('CREATE UNIQUE INDEX', $externalPart);
    }

    public function test_column_foreign_key_emitted_as_external_alter_statement(): void
    {
        $sql = (new Table('orders', columns: [
            new Column('id', 'INT', nullable: false, indexes: [
                new Index(columns: ['id'], type: IndexType::PRIMARY),
            ]),
            new Column('user_id', 'INT', nullable: false, foreignKey: new ForeignKey(
                referencedTable: 'users',
                referencedColumn: 'id',
                name: 'fk_orders_user',
            )),
        ]))->toSql('pgsql');

        self::assertStringContainsString(
            'ALTER TABLE orders ADD CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id)',
            $sql,
        );
    }

    public function test_column_check_constraint_emitted_as_external_alter_statement(): void
    {
        $sql = (new Table('users', columns: [
            new Column('age', 'INT', checkConstraint: new CheckConstraint('age >= 0', name: 'chk_age')),
        ]))->toSql('pgsql');

        self::assertStringContainsString(
            'ALTER TABLE users ADD CONSTRAINT chk_age CHECK (age >= 0);',
            $sql,
        );
    }

    // ── Table-level checks via constructor ───────────────────────────────────

    public function test_table_level_check_emitted(): void
    {
        $sql = (new Table('users', columns: [
            new Column('id', 'INT', nullable: false),
        ], checks: [
            new CheckConstraint('id > 0', name: 'chk_positive_id'),
        ]))->toSql('pgsql');

        self::assertStringContainsString(
            'ALTER TABLE users ADD CONSTRAINT chk_positive_id CHECK (id > 0);',
            $sql,
        );
    }

    // ── Table-level indexes via constructor ──────────────────────────────────

    public function test_table_level_index_emitted_as_external_for_pgsql(): void
    {
        $sql = (new Table('users', columns: [
            new Column('email', 'VARCHAR(255)'),
        ], indexes: [
            new Index(columns: ['email'], type: IndexType::INDEX),
        ]))->toSql('pgsql');

        self::assertStringContainsString('CREATE INDEX users_email_idx', $sql);
    }

    // ── Mutation helpers ─────────────────────────────────────────────────────

    public function test_add_column_appends_and_returns_alter_table(): void
    {
        $table = new Table('users', columns: []);
        $sql = $table->addColumn(new Column('email', 'VARCHAR(255)'));

        self::assertSame('ALTER TABLE users ADD COLUMN email VARCHAR(255);', $sql);
        self::assertCount(1, $table->columns);
    }

    public function test_drop_column_removes_and_returns_alter_table(): void
    {
        $table = new Table('users', columns: [new Column('email', 'VARCHAR(255)')]);
        $sql = $table->dropColumn('email');

        self::assertSame('ALTER TABLE users DROP COLUMN email;', $sql);
        self::assertCount(0, $table->columns);
    }

    public function test_drop_index_mysql_vs_pgsql(): void
    {
        $table = new Table('users', columns: [], indexes: [
            new Index(columns: ['email'], name: 'idx_email'),
        ]);
        self::assertSame('ALTER TABLE users DROP INDEX idx_email;', $table->dropIndex('idx_email', 'mysql'));

        $table2 = new Table('users', columns: [], indexes: [
            new Index(columns: ['email'], name: 'idx_email'),
        ]);
        self::assertSame('DROP INDEX idx_email;', $table2->dropIndex('idx_email', 'pgsql'));
    }

    public function test_drop_index_unsupported_dialect_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Table('users', columns: []))->dropIndex('x', 'sqlite');
    }

    public function test_drop_foreign_key_mysql_uses_drop_foreign_key(): void
    {
        $table = new Table('orders', columns: []);
        self::assertSame(
            'ALTER TABLE orders DROP FOREIGN KEY fk_x;',
            $table->dropForeignKey('fk_x', 'mysql'),
        );
    }

    public function test_drop_foreign_key_pgsql_uses_drop_constraint(): void
    {
        $table = new Table('orders', columns: []);
        self::assertSame(
            'ALTER TABLE orders DROP CONSTRAINT fk_x;',
            $table->dropForeignKey('fk_x', 'pgsql'),
        );
    }

    public function test_drop_foreign_key_unsupported_dialect_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Table('orders', columns: []))->dropForeignKey('fk_x', 'sqlite');
    }

    public function test_drop_check_constraint_mysql_and_pgsql_share_syntax(): void
    {
        $a = (new Table('users', columns: []))->dropCheckConstraint('chk_x', 'mysql');
        $b = (new Table('users', columns: []))->dropCheckConstraint('chk_x', 'pgsql');
        self::assertSame('ALTER TABLE users DROP CONSTRAINT chk_x;', $a);
        self::assertSame('ALTER TABLE users DROP CONSTRAINT chk_x;', $b);
    }

    public function test_drop_check_constraint_unsupported_dialect_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Table('users', columns: []))->dropCheckConstraint('chk_x', 'sqlite');
    }

    // ── Table-level foreignKeys (requires columnName) ────────────────────────

    public function test_table_level_foreign_key_with_column_name_renders(): void
    {
        $fk = new ForeignKey(
            referencedTable: 'users',
            referencedColumn: 'id',
            name: 'fk_orders_user',
            columnName: 'user_id',
        );
        $sql = (new Table('orders', columns: [
            new Column('id', 'INT', nullable: false),
            new Column('user_id', 'INT', nullable: false),
        ], foreignKeys: [$fk]))->toSql('pgsql');

        self::assertStringContainsString(
            'ALTER TABLE orders ADD CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id)',
            $sql,
        );
    }

    public function test_table_level_foreign_key_without_column_name_throws(): void
    {
        $fk = new ForeignKey('users', 'id'); // columnName not set
        $table = new Table('orders', columns: [
            new Column('user_id', 'INT'),
        ], foreignKeys: [$fk]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must declare \$columnName/');
        $table->toSql('pgsql');
    }

    public function test_add_foreign_key_uses_column_name_field(): void
    {
        $table = new Table('orders', columns: []);
        $fk = new ForeignKey(
            referencedTable: 'users',
            referencedColumn: 'id',
            name: 'fk_orders_user',
            columnName: 'user_id',
        );

        $sql = $table->addForeignKey($fk, 'pgsql');
        self::assertStringContainsString('FOREIGN KEY (user_id) REFERENCES users(id)', $sql);
        self::assertStringStartsWith('ALTER TABLE orders ADD ', $sql);
    }

    public function test_add_foreign_key_without_column_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must declare \$columnName/');
        (new Table('orders', columns: []))
            ->addForeignKey(new ForeignKey('users', 'id'), 'pgsql');
    }
}
