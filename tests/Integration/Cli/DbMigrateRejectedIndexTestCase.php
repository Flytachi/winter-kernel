<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli;

use Flytachi\Winter\Console\Command\Db;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\IntegrationTestCase;

/**
 * `db migrate` must report an index the server rejected as FAILED, not as EXIST.
 *
 * On MySQL and MariaDB every error from CREATE INDEX carries SQLSTATE 42000 — a duplicate
 * name, an unknown column, a syntax error alike — and only the driver code tells them apart
 * (1061 is "Duplicate key name"). Reading the whole class as "already exists" showed a
 * rejected index in yellow as EXIST: the schema silently lacked it, and nothing said so.
 */
abstract class DbMigrateRejectedIndexTestCase extends IntegrationTestCase
{
    private static ?string $previousPathRoot = null;

    abstract protected static function fixturePath(): string;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$schemaName === '') {
            return;
        }
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

    private function runDbMigrate(): string
    {
        ob_start();
        try {
            (new Db(['arguments' => ['db', 'migrate'], 'flags' => ['e', 's', 't', 'i', 'c'], 'options' => []]))->handle();
        } finally {
            $out = (string) ob_get_clean();
        }
        return preg_replace('/\e\[[0-9;]*m/', '', $out);
    }

    /** The output line that reports the given index. */
    private static function indexLine(string $output, string $index): string
    {
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, "'{$index}'")) {
                return $line;
            }
        }
        self::fail("no line for index {$index} in:\n{$output}");
    }

    public function test_a_rejected_index_is_reported_as_failed_not_as_existing(): void
    {
        $line = self::indexLine($this->runDbMigrate(), 'migr_badix_code_idx');

        self::assertStringContainsString('FAILED', $line);
        self::assertStringNotContainsString('EXIST', $line);

        $stmt = self::pdoOnTestSchema()->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = :s AND index_name = :i',
        );
        $stmt->execute([':s' => self::$schemaName, ':i' => 'migr_badix_code_idx']);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'and indeed the index does not exist');
    }
}
