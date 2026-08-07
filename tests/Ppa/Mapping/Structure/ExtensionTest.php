<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Mapping\Structure;

use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Extension;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\StructureInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionTest extends TestCase
{
    // ── Construction ─────────────────────────────────────────────────────────

    public function test_implements_structure_interface(): void
    {
        self::assertInstanceOf(StructureInterface::class, new Extension('uuid-ossp'));
    }

    public function test_constructor_defaults(): void
    {
        $e = new Extension('uuid-ossp');
        self::assertSame('uuid-ossp', $e->name);
        self::assertNull($e->version);
        self::assertNull($e->schema);
        self::assertFalse($e->cascade);
    }

    public function test_constructor_full_args(): void
    {
        $e = new Extension('postgis', version: '3.4', schema: 'gis', cascade: true);
        self::assertSame('postgis', $e->name);
        self::assertSame('3.4', $e->version);
        self::assertSame('gis', $e->schema);
        self::assertTrue($e->cascade);
    }

    // ── pgsql SQL emission ───────────────────────────────────────────────────

    public function test_pgsql_name_only(): void
    {
        $sql = (new Extension('uuid-ossp'))->toSql('pgsql');
        self::assertSame('CREATE EXTENSION IF NOT EXISTS "uuid-ossp";', $sql);
    }

    public function test_pgsql_cascade(): void
    {
        $sql = (new Extension('pgcrypto', cascade: true))->toSql('pgsql');
        self::assertSame('CREATE EXTENSION IF NOT EXISTS "pgcrypto" CASCADE;', $sql);
    }

    public function test_pgsql_with_schema(): void
    {
        $sql = (new Extension('postgis', schema: 'gis'))->toSql('pgsql');
        self::assertSame('CREATE EXTENSION IF NOT EXISTS "postgis" WITH SCHEMA gis;', $sql);
    }

    public function test_pgsql_with_version(): void
    {
        $sql = (new Extension('postgis', version: '3.4'))->toSql('pgsql');
        self::assertSame("CREATE EXTENSION IF NOT EXISTS \"postgis\" VERSION '3.4';", $sql);
    }

    public function test_pgsql_with_all_options(): void
    {
        $sql = (new Extension('postgis', version: '3.4', schema: 'gis', cascade: true))->toSql('pgsql');
        self::assertSame(
            "CREATE EXTENSION IF NOT EXISTS \"postgis\" WITH SCHEMA gis VERSION '3.4' CASCADE;",
            $sql,
        );
    }

    public function test_pgsql_default_dialect_parameter_is_mysql_so_throws_without_explicit_pgsql(): void
    {
        // toSql() defaults to 'mysql' per StructureInterface convention.
        // Callers must explicitly pass 'pgsql' for extensions.
        $this->expectException(\InvalidArgumentException::class);
        (new Extension('uuid-ossp'))->toSql();
    }

    // ── non-pgsql dialects all throw ─────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function nonPgsqlDialects(): array
    {
        return [
            'mysql (also returned by mariadb)' => ['mysql'],
            'sqlite'                           => ['sqlite'],
            'oci'                              => ['oci'],
            'sqlsrv'                           => ['sqlsrv'],
            'unknown'                          => ['unknown'],
            'empty'                            => [''],
        ];
    }

    #[DataProvider('nonPgsqlDialects')]
    public function test_non_pgsql_dialect_throws(string $dialect): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported dialect: {$dialect}");
        (new Extension('uuid-ossp'))->toSql($dialect);
    }
}
