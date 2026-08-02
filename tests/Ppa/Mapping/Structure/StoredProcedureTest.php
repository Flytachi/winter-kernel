<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Mapping\Structure;

use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\StoredProcedure;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\StructureInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StoredProcedureTest extends TestCase
{
    public function test_implements_structure_interface(): void
    {
        self::assertInstanceOf(StructureInterface::class, new StoredProcedure('p', 'body'));
    }

    public function test_mysql_uses_delimiter_form(): void
    {
        $sql = (new StoredProcedure('my_proc', 'PERFORM 1;'))->toSql('mysql');
        $expected = "DELIMITER //\nCREATE PROCEDURE my_proc()\nBEGIN\nPERFORM 1;\nEND //\nDELIMITER ;";
        self::assertSame($expected, $sql);
    }

    public function test_pgsql_uses_create_or_replace_function_with_dollar_quote(): void
    {
        $sql = (new StoredProcedure('my_proc', 'PERFORM 1;'))->toSql('pgsql');
        $expected = "CREATE OR REPLACE FUNCTION my_proc() RETURNS VOID AS \$\$\n"
            . "BEGIN\nPERFORM 1;\nEND;\n\$\$ LANGUAGE plpgsql;";
        self::assertSame($expected, $sql);
    }

    public function test_pgsql_output_contains_plpgsql_language_marker(): void
    {
        $sql = (new StoredProcedure('p', 'NULL'))->toSql('pgsql');
        self::assertStringContainsString('LANGUAGE plpgsql', $sql);
    }

    /** @return array<string, array{0: string}> */
    public static function unsupportedDialects(): array
    {
        return [
            'sqlite' => ['sqlite'],
            'oci'    => ['oci'],
            'empty'  => [''],
        ];
    }

    #[DataProvider('unsupportedDialects')]
    public function test_unsupported_dialect_throws(string $dialect): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported dialect for Stored Procedure: {$dialect}");
        (new StoredProcedure('p', 'NULL'))->toSql($dialect);
    }

    public function test_invalid_name_throws_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StoredProcedure('bad-name', 'NULL');
    }
}
