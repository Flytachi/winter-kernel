<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping\Structure;

use Flytachi\Winter\K2\Ppa\Mapping\Structure\StructureInterface;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Trigger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TriggerTest extends TestCase
{
    public function test_implements_structure_interface(): void
    {
        self::assertInstanceOf(
            StructureInterface::class,
            new Trigger('t', 'users', 'INSERT', 'BEFORE', 'SET NEW.created_at = NOW()'),
        );
    }

    public function test_mysql_renders_full_trigger_body(): void
    {
        $sql = (new Trigger(
            name: 'set_created_at',
            tableName: 'users',
            event: 'INSERT',
            timing: 'BEFORE',
            definition: 'SET NEW.created_at = NOW()',
        ))->toSql('mysql');

        self::assertSame(
            'CREATE TRIGGER set_created_at BEFORE INSERT ON users FOR EACH ROW SET NEW.created_at = NOW();',
            $sql,
        );
    }

    public function test_pgsql_renders_execute_function_form(): void
    {
        $sql = (new Trigger(
            name: 'audit_log',
            tableName: 'orders',
            event: 'UPDATE',
            timing: 'AFTER',
            definition: 'audit_orders_change',
        ))->toSql('pgsql');

        self::assertSame(
            'CREATE TRIGGER audit_log AFTER UPDATE ON orders FOR EACH ROW EXECUTE FUNCTION audit_orders_change();',
            $sql,
        );
    }

    public function test_default_granularity_is_row(): void
    {
        $sql = (new Trigger('t', 'users', 'INSERT', 'BEFORE', 'def'))->toSql('mysql');
        self::assertStringContainsString('FOR EACH ROW', $sql);
    }

    public function test_statement_granularity_overrides_default(): void
    {
        $sql = (new Trigger('t', 'users', 'INSERT', 'AFTER', 'def', granularity: 'STATEMENT'))
            ->toSql('pgsql');
        self::assertStringContainsString('FOR EACH STATEMENT', $sql);
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
        $this->expectExceptionMessage("Unsupported dialect for Trigger: {$dialect}");
        (new Trigger('t', 'users', 'INSERT', 'BEFORE', 'def'))->toSql($dialect);
    }

    public function test_invalid_name_throws_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Trigger('bad-trigger', 'users', 'INSERT', 'BEFORE', 'def');
    }
}
