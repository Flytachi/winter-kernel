<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping\Structure;

use Flytachi\Winter\K2\Ppa\Mapping\Structure\StructureInterface;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    public function test_implements_structure_interface(): void
    {
        self::assertInstanceOf(StructureInterface::class, new View('v', 'SELECT 1'));
    }

    public function test_emits_create_view_for_mysql(): void
    {
        $sql = (new View('active_users', 'SELECT id FROM users WHERE active = 1'))->toSql('mysql');
        self::assertSame('CREATE VIEW active_users AS SELECT id FROM users WHERE active = 1;', $sql);
    }

    public function test_pgsql_output_identical_to_mysql(): void
    {
        // Current implementation is dialect-agnostic.
        $mysql = (new View('v', 'SELECT 1'))->toSql('mysql');
        $pgsql = (new View('v', 'SELECT 1'))->toSql('pgsql');
        self::assertSame($mysql, $pgsql);
    }

    public function test_invalid_name_throws_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new View('bad-view', 'SELECT 1');
    }

    public function test_empty_name_throws_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new View('', 'SELECT 1');
    }
}
