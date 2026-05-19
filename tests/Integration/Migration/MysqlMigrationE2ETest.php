<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Migration;

use Flytachi\Winter\K2\Ppa\Mapping\Constants\FKAction;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\IndexType;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\CheckConstraint;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Column;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\ForeignKey;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Index;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Table;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end migration tests for MySQL — emit SQL via the Structure objects,
 * execute against a real MySQL server, then introspect information_schema.
 *
 * The MariaDB variant inherits this class and only overrides driverFlavour();
 * MySQL 8 and MariaDB 10.11 share information_schema layout for the things
 * we assert, so the test bodies are reused.
 */
#[Group('integration')]
class MysqlMigrationE2ETest extends IntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mysql';
    }

    /**
     * MySQL/MariaDB: unqualified table DDL needs the per-class database active.
     * pdoOnTestSchema() opens a connection and runs `USE wk_xxx` so subsequent
     * `CREATE TABLE users_simple` lands inside the test database.
     */
    private function pdo(): \PDO
    {
        return self::pdoOnTestSchema();
    }

    private function runDdl(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    // ── CREATE TABLE ─────────────────────────────────────────────────────────

    public function test_create_simple_table_inserts_into_information_schema(): void
    {
        $table = new Table('users_simple', columns: [
            new Column('id', 'INT', nullable: false, indexes: [
                new Index(columns: ['id'], type: IndexType::PRIMARY),
            ]),
            new Column('email', 'VARCHAR(255)', nullable: false),
        ]);
        $this->runDdl($table->toSql('mysql'));

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
        ]);
        $this->runDdl($table->toSql('mysql'));

        $stmt = $this->pdo()->prepare(
            'SELECT column_name, data_type, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_schema = :s AND table_name = :t
             ORDER BY ordinal_position'
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'users_columns']);
        // MySQL/MariaDB return information_schema column names in upper case
        // (COLUMN_NAME, DATA_TYPE, ...). Normalise to lower case so the assertions
        // are dialect-agnostic.
        $cols = array_map('array_change_key_case', $stmt->fetchAll(\PDO::FETCH_ASSOC));

        self::assertCount(4, $cols);
        self::assertSame('id', $cols[0]['column_name']);
        self::assertSame('int', strtolower((string) $cols[0]['data_type']));
        self::assertSame('NO', $cols[0]['is_nullable']);

        self::assertSame('varchar', strtolower((string) $cols[1]['data_type']));

        self::assertSame('YES', $cols[2]['is_nullable']);
    }

    // ── INDEX ────────────────────────────────────────────────────────────────

    public function test_unique_index_appears_in_information_schema(): void
    {
        $table = new Table('users_index', columns: [
            new Column('id', 'INT', nullable: false),
            new Column('email', 'VARCHAR(255)', nullable: false, indexes: [
                new Index(columns: ['email'], type: IndexType::UNIQUE),
            ]),
        ]);
        $this->runDdl($table->toSql('mysql'));

        $stmt = $this->pdo()->prepare(
            'SELECT index_name FROM information_schema.statistics
             WHERE table_schema = :s AND table_name = :t'
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'users_index']);
        $names = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('users_index_email_udx', $names);
    }

    // ── FOREIGN KEY ──────────────────────────────────────────────────────────

    public function test_foreign_key_appears_in_information_schema(): void
    {
        $parent = new Table('parent_fk', columns: [
            new Column('id', 'INT', nullable: false, indexes: [
                new Index(columns: ['id'], type: IndexType::PRIMARY),
            ]),
        ]);
        $this->runDdl($parent->toSql('mysql'));

        $child = new Table('child_fk', columns: [
            new Column('id', 'INT', nullable: false, indexes: [
                new Index(columns: ['id'], type: IndexType::PRIMARY),
            ]),
            new Column('parent_id', 'INT', nullable: false, foreignKey: new ForeignKey(
                referencedTable: 'parent_fk',
                referencedColumn: 'id',
                onUpdate: FKAction::CASCADE,
                onDelete: FKAction::CASCADE,
                name: 'fk_child_parent',
            )),
        ]);
        $this->runDdl($child->toSql('mysql'));

        $stmt = $this->pdo()->prepare(
            "SELECT constraint_name
             FROM information_schema.table_constraints
             WHERE table_schema = :s AND table_name = :t AND constraint_type = 'FOREIGN KEY'"
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'child_fk']);
        $names = array_map('strtolower', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        self::assertContains('fk_child_parent', $names);
    }

    // ── CHECK CONSTRAINT (MySQL 8+, MariaDB 10.2+) ──────────────────────────

    public function test_check_constraint_appears_in_information_schema(): void
    {
        $table = new Table('users_check', columns: [
            new Column('id', 'INT', nullable: false),
            new Column('age', 'INT', checkConstraint: new CheckConstraint(
                expression: 'age >= 0',
                name: 'chk_age_nonneg',
            )),
        ]);
        $this->runDdl($table->toSql('mysql'));

        $stmt = $this->pdo()->prepare(
            "SELECT constraint_name
             FROM information_schema.table_constraints
             WHERE table_schema = :s AND table_name = :t AND constraint_type = 'CHECK'"
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'users_check']);
        $names = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        self::assertContains('chk_age_nonneg', $names);
    }
}
