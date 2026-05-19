<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Migration;

use Flytachi\Winter\K2\Ppa\Mapping\Constants\FKAction;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexType;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\CheckConstraint;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Column;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Extension;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\ForeignKey;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Index;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Table;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end migration tests for PostgreSQL — emit SQL via the Structure
 * objects, execute against a real PG server, then introspect system catalogs
 * to verify the actual DB state.
 *
 * Each test uses a uniquely-named table so they don't collide. The class's
 * per-test-class schema is dropped wholesale in tearDownAfterClass.
 */
#[Group('integration')]
final class PgMigrationE2ETest extends IntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'pgsql';
    }

    private function pdo(): \PDO
    {
        return self::rawPdo();
    }

    /** Executes possibly-multi-statement SQL produced by Table::toSql(). */
    private function runDdl(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    // ── CREATE TABLE ─────────────────────────────────────────────────────────

    public function test_create_simple_table_with_primary_key_inserts_into_pg_tables(): void
    {
        $table = new Table('users_simple', columns: [
            new Column('id', 'INT', nullable: false, indexes: [
                new Index(columns: ['id'], type: IndexType::PRIMARY),
            ]),
            new Column('email', 'VARCHAR(255)', nullable: false),
        ], schema: self::$schemaName);

        $this->runDdl($table->toSql('pgsql'));

        // information_schema.tables — table is present in this class's schema.
        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = :s AND table_name = :t'
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'users_simple']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_columns_have_correct_types_and_nullability(): void
    {
        $table = new Table('users_columns', columns: [
            new Column('id', 'INT', nullable: false),
            new Column('email', 'VARCHAR(255)', nullable: false),
            new Column('age', 'INT', nullable: true),
            new Column('status', 'VARCHAR(16)', nullable: false, default: "'active'"),
        ], schema: self::$schemaName);
        $this->runDdl($table->toSql('pgsql'));

        $stmt = $this->pdo()->prepare(
            'SELECT column_name, data_type, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_schema = :s AND table_name = :t
             ORDER BY ordinal_position'
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'users_columns']);
        $cols = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(4, $cols);
        self::assertSame('id', $cols[0]['column_name']);
        self::assertSame('integer', $cols[0]['data_type']);
        self::assertSame('NO', $cols[0]['is_nullable']);

        self::assertSame('email', $cols[1]['column_name']);
        self::assertSame('character varying', $cols[1]['data_type']);

        self::assertSame('age', $cols[2]['column_name']);
        self::assertSame('YES', $cols[2]['is_nullable']);

        self::assertSame('status', $cols[3]['column_name']);
        self::assertStringStartsWith("'active'", (string) $cols[3]['column_default']);
    }

    // ── INDEX ────────────────────────────────────────────────────────────────

    public function test_unique_index_appears_in_pg_indexes(): void
    {
        $table = new Table('users_index', columns: [
            new Column('id', 'INT', nullable: false),
            new Column('email', 'VARCHAR(255)', nullable: false, indexes: [
                new Index(columns: ['email'], type: IndexType::UNIQUE),
            ]),
        ], schema: self::$schemaName);
        $this->runDdl($table->toSql('pgsql'));

        $stmt = $this->pdo()->prepare(
            'SELECT indexname FROM pg_indexes WHERE schemaname = :s AND tablename = :t'
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'users_index']);
        $names = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('users_index_email_udx', $names);
    }

    public function test_partial_index_with_where_clause(): void
    {
        $table = new Table('users_partial', columns: [
            new Column('id', 'INT', nullable: false),
            new Column('email', 'VARCHAR(255)', nullable: false, indexes: [
                new Index(columns: ['email'], where: "email <> ''"),
            ]),
        ], schema: self::$schemaName);
        $this->runDdl($table->toSql('pgsql'));

        $stmt = $this->pdo()->prepare(
            "SELECT indexdef FROM pg_indexes WHERE schemaname = :s AND tablename = :t
             AND indexname = 'users_partial_email_idx'"
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'users_partial']);
        $def = (string) $stmt->fetchColumn();
        self::assertStringContainsStringIgnoringCase('WHERE', $def);
    }

    // ── FOREIGN KEY ──────────────────────────────────────────────────────────

    public function test_foreign_key_appears_in_information_schema(): void
    {
        $parent = new Table('parent_fk', columns: [
            new Column('id', 'INT', nullable: false, indexes: [
                new Index(columns: ['id'], type: IndexType::PRIMARY),
            ]),
        ], schema: self::$schemaName);
        $this->runDdl($parent->toSql('pgsql'));

        $child = new Table('child_fk', columns: [
            new Column('id', 'INT', nullable: false, indexes: [
                new Index(columns: ['id'], type: IndexType::PRIMARY),
            ]),
            new Column('parent_id', 'INT', nullable: false, foreignKey: new ForeignKey(
                referencedTable: self::$schemaName . '.parent_fk',
                referencedColumn: 'id',
                onUpdate: FKAction::CASCADE,
                onDelete: FKAction::CASCADE,
                name: 'fk_child_parent',
            )),
        ], schema: self::$schemaName);
        $this->runDdl($child->toSql('pgsql'));

        $stmt = $this->pdo()->prepare(
            "SELECT constraint_name
             FROM information_schema.table_constraints
             WHERE table_schema = :s AND table_name = :t AND constraint_type = 'FOREIGN KEY'"
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'child_fk']);
        $names = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('fk_child_parent', $names);
    }

    // ── CHECK CONSTRAINT ─────────────────────────────────────────────────────

    public function test_check_constraint_appears_in_information_schema(): void
    {
        $table = new Table('users_check', columns: [
            new Column('id', 'INT', nullable: false),
            new Column('age', 'INT', checkConstraint: new CheckConstraint(
                expression: 'age >= 0',
                name: 'chk_age_nonneg',
            )),
        ], schema: self::$schemaName);
        $this->runDdl($table->toSql('pgsql'));

        $stmt = $this->pdo()->prepare(
            "SELECT cc.check_clause
             FROM information_schema.check_constraints cc
             JOIN information_schema.constraint_column_usage ccu
                  ON cc.constraint_name = ccu.constraint_name
             WHERE ccu.table_schema = :s AND ccu.table_name = :t
                   AND cc.constraint_name = 'chk_age_nonneg'"
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'users_check']);
        $clause = (string) $stmt->fetchColumn();
        self::assertStringContainsString('age', $clause);
    }

    // ── EXTENSION (pgsql-only) ───────────────────────────────────────────────

    public function test_install_pgcrypto_extension_idempotent(): void
    {
        // pgcrypto ships with every standard PG distribution — no extra setup needed.
        $ext = new Extension('pgcrypto');
        $this->runDdl($ext->toSql('pgsql'));
        // Re-running must be a no-op thanks to IF NOT EXISTS.
        $this->runDdl($ext->toSql('pgsql'));

        $stmt = $this->pdo()->prepare(
            'SELECT 1 FROM pg_extension WHERE extname = :name'
        );
        $stmt->execute([':name' => 'pgcrypto']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }
}
