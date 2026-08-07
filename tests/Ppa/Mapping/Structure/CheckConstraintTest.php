<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Mapping\Structure;

use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\CheckConstraint;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\StructureInterface;
use PHPUnit\Framework\TestCase;

final class CheckConstraintTest extends TestCase
{
    public function test_implements_structure_interface(): void
    {
        self::assertInstanceOf(StructureInterface::class, new CheckConstraint('x > 0'));
    }

    public function test_explicit_name_used_verbatim(): void
    {
        $sql = (new CheckConstraint('age >= 18', name: 'chk_adult'))->toSql('users');
        self::assertSame('ALTER TABLE users ADD CONSTRAINT chk_adult CHECK (age >= 18)', $sql);
    }

    public function test_auto_name_uses_md5_of_expression_for_table_without_schema(): void
    {
        $expression = 'age >= 18';
        $expected = 'ALTER TABLE users ADD CONSTRAINT chk_users_' . md5($expression) . ' CHECK (age >= 18)';
        self::assertSame($expected, (new CheckConstraint($expression))->toSql('users'));
    }

    public function test_auto_name_uses_table_part_after_dot_for_schema_qualified_table(): void
    {
        $expression = "status IN ('active', 'inactive')";
        $expected = 'ALTER TABLE public.users ADD CONSTRAINT chk_users_'
            . md5($expression) . " CHECK (status IN ('active', 'inactive'))";
        self::assertSame($expected, (new CheckConstraint($expression))->toSql('public.users'));
    }

    public function test_same_expression_produces_stable_auto_name(): void
    {
        $a = (new CheckConstraint('age > 0'))->toSql('users');
        $b = (new CheckConstraint('age > 0'))->toSql('users');
        self::assertSame($a, $b);
    }

    public function test_dialect_parameter_is_currently_ignored_in_output(): void
    {
        // CheckConstraint emits the same SQL for any dialect — it's expressed
        // entirely via ALTER TABLE / ADD CONSTRAINT, common to mysql and pgsql.
        $mysql = (new CheckConstraint('age > 0', name: 'chk'))->toSql('users', 'mysql');
        $pgsql = (new CheckConstraint('age > 0', name: 'chk'))->toSql('users', 'pgsql');
        self::assertSame($mysql, $pgsql);
    }

    public function test_invalid_explicit_name_throws_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CheckConstraint('age > 0', name: 'bad-name');
    }
}
