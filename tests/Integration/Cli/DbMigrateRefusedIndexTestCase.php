<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli;

use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\IntegrationTestCase;

/**
 * What a server could never build as the entity declares it is refused before anything runs:
 * a GIN index on MySQL / MariaDB. Nothing of the database is created, the reason is said —
 * and the command ends with 1, so a deploy script does not take it for a success.
 *
 * Run in a process of its own: a refused `db migrate` exits.
 */
abstract class DbMigrateRefusedIndexTestCase extends IntegrationTestCase
{
    abstract protected static function fixturePath(): string;

    /** @return array{int, string} The exit code and what was printed, colours stripped. */
    private function runDbMigrate(): array
    {
        $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        $root = var_export(static::fixturePath(), true);
        $schema = var_export(self::$schemaName, true);
        $code = "require {$autoload};"
            . ' \\Flytachi\\Winter\\Kernel\\Tests\\Integration\\Fixtures\\IntegrationTestCase::$schemaName = ' . $schema . ';'
            . ' \\Flytachi\\Winter\\Kernel\\Kernel::$pathRoot = ' . $root . ';'
            . " (new \\Flytachi\\Winter\\Console\\Command\\Db(['arguments' => ['db', 'migrate'],"
            . " 'flags' => ['e', 's', 't', 'i', 'c'], 'options' => []]))->handle();";
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out, $exit);
        return [$exit, (string) preg_replace('/\e\[[0-9;]*m/', '', implode("\n", $out))];
    }

    public function test_an_index_the_server_has_no_kind_of_is_refused_and_ends_with_one(): void
    {
        [$exit, $out] = $this->runDbMigrate();

        self::assertSame(1, $exit, "a refused migrate must not look like a success:\n{$out}");
        self::assertStringContainsString('have no GIN index', $out);
        self::assertStringContainsString('REFUSED — nothing created', $out);
        self::assertStringContainsString('nothing was created for', $out);

        $stmt = self::pdoOnTestSchema()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :s AND table_name = :t',
        );
        $stmt->execute([':s' => self::$schemaName, ':t' => 'migr_ginix']);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'and indeed nothing of it was created');
    }
}
