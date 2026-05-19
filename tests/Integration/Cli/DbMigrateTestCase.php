<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Cli;

use Flytachi\Winter\Console\Command\Db;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;

/**
 * End-to-end test of the `Db` console command's migrate flow.
 *
 * Phase C.5 — exercises the FULL pipeline:
 *   Scanner finds Repository under Kernel::$pathRoot
 *     → PPAMapping::scanningDeclaration builds Declaration
 *     → DeclarationItem auto-collects #[Migratable] / #[Extension] from DbConfig
 *     → Db::processItemData generates DDL strings from Structure objects
 *     → executes each statement via CDO
 *
 * After running, raw PDO checks information_schema to verify the table,
 * unique index, and (for pgsql) the pgcrypto extension actually exist.
 *
 * Concrete subclasses set:
 *   - driverFlavour (pgsql / mysql / mariadb)
 *   - fixturePath — Kernel::$pathRoot for this driver's repo subdirectory
 */
abstract class DbMigrateTestCase extends IntegrationTestCase
{
    private static ?string $previousPathRoot = null;

    /** Absolute path containing this driver's MigrRepo file. */
    abstract protected static function fixturePath(): string;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$schemaName === '') {
            return; // skipped — no env
        }

        // Save and override Kernel::$pathRoot so PPAMapping's scanner finds
        // only this driver's MigrRepo (the other drivers' repos live in
        // sibling directories and stay out of scope).
        self::$previousPathRoot = Kernel::$pathRoot ?? null;
        Kernel::$pathRoot = static::fixturePath();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$previousPathRoot !== null) {
            Kernel::$pathRoot = self::$previousPathRoot;
            self::$previousPathRoot = null;
        }
        parent::tearDownAfterClass();
    }

    /** Capture stdout to keep PHPUnit output clean; return the captured text. */
    protected function runDbMigrate(array $flags = ['e', 's', 't', 'i', 'c']): string
    {
        ob_start();
        try {
            (new Db([
                'arguments' => ['db', 'migrate'],
                'flags'     => $flags,
                'options'   => [],
            ]))->handle();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    // ── Shared assertions ───────────────────────────────────────────────────

    public function test_migrate_creates_table_in_test_schema(): void
    {
        $this->runDbMigrate();

        $stmt = self::pdoOnTestSchema()->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = :s AND table_name = :t',
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'migr_widgets']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_migrate_creates_unique_index_on_name_column(): void
    {
        $this->runDbMigrate();

        $indexName = 'migr_widgets_name_udx';
        $stmt = match (static::driverFlavour()) {
            'pgsql' => self::pdoOnTestSchema()->prepare(
                'SELECT 1 FROM pg_indexes WHERE schemaname = :s AND indexname = :i',
            ),
            'mysql', 'mariadb' => self::pdoOnTestSchema()->prepare(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = :s AND index_name = :i LIMIT 1',
            ),
        };
        $stmt->execute([':s' => self::$schemaName, ':i' => $indexName]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_migrate_is_idempotent_re_running_is_a_no_op(): void
    {
        $this->runDbMigrate();
        // Second invocation must not throw / fail. Db catches per-statement
        // errors and badges EXIST for known duplicate codes; the table
        // remains in place either way.
        $secondRun = $this->runDbMigrate();
        self::assertStringNotContainsString('FAILED', $secondRun);

        $stmt = self::pdoOnTestSchema()->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = :s AND table_name = :t',
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'migr_widgets']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }
}
