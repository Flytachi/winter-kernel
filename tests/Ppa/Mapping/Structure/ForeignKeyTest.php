<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping\Structure;

use Flytachi\Winter\K2\Ppa\Mapping\Constants\FKAction;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\ForeignKey;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\StructureInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ForeignKeyTest extends TestCase
{
    public function test_implements_structure_interface(): void
    {
        self::assertInstanceOf(StructureInterface::class, new ForeignKey('users', 'id'));
    }

    public function test_default_actions_are_restrict(): void
    {
        $fk = new ForeignKey('users', 'id');
        self::assertSame(FKAction::RESTRICT, $fk->onUpdate);
        self::assertSame(FKAction::RESTRICT, $fk->onDelete);
    }

    public function test_default_name_uses_table_and_column(): void
    {
        $sql = (new ForeignKey('users', 'id'))->toSql('orders', 'user_id');
        self::assertSame(
            'ALTER TABLE orders ADD CONSTRAINT fk_orders_user_id FOREIGN KEY (user_id)'
            . ' REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT',
            $sql,
        );
    }

    public function test_default_name_uses_table_part_after_dot_for_schema_qualified_table(): void
    {
        $sql = (new ForeignKey('public.users', 'id'))->toSql('public.orders', 'user_id');
        self::assertSame(
            'ALTER TABLE public.orders ADD CONSTRAINT fk_orders_user_id FOREIGN KEY (user_id)'
            . ' REFERENCES public.users(id) ON DELETE RESTRICT ON UPDATE RESTRICT',
            $sql,
        );
    }

    public function test_explicit_name_used_verbatim(): void
    {
        $sql = (new ForeignKey('users', 'id', name: 'fk_orders_belongs_to_user'))
            ->toSql('orders', 'user_id');
        self::assertStringContainsString('ADD CONSTRAINT fk_orders_belongs_to_user FOREIGN KEY', $sql);
    }

    public function test_invalid_explicit_name_throws_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ForeignKey('users', 'id', name: 'bad-name');
    }

    // ── All FKAction values render correctly ─────────────────────────────────

    /** @return array<string, array{0: FKAction, 1: string}> */
    public static function allActions(): array
    {
        return [
            'RESTRICT'    => [FKAction::RESTRICT, 'RESTRICT'],
            'NO_ACTION'   => [FKAction::NO_ACTION, 'NO ACTION'],
            'SET_DEFAULT' => [FKAction::SET_DEFAULT, 'SET DEFAULT'],
            'SET_NULL'    => [FKAction::SET_NULL, 'SET NULL'],
            'CASCADE'     => [FKAction::CASCADE, 'CASCADE'],
        ];
    }

    #[DataProvider('allActions')]
    public function test_on_delete_action_renders(FKAction $action, string $rendered): void
    {
        $sql = (new ForeignKey('users', 'id', onDelete: $action))->toSql('orders', 'user_id');
        self::assertStringContainsString("ON DELETE {$rendered}", $sql);
        self::assertStringContainsString('ON UPDATE RESTRICT', $sql);
    }

    #[DataProvider('allActions')]
    public function test_on_update_action_renders(FKAction $action, string $rendered): void
    {
        $sql = (new ForeignKey('users', 'id', onUpdate: $action))->toSql('orders', 'user_id');
        self::assertStringContainsString("ON UPDATE {$rendered}", $sql);
        self::assertStringContainsString('ON DELETE RESTRICT', $sql);
    }

    public function test_combined_on_update_and_on_delete(): void
    {
        $sql = (new ForeignKey('users', 'id', onUpdate: FKAction::CASCADE, onDelete: FKAction::SET_NULL))
            ->toSql('orders', 'user_id');
        self::assertStringContainsString('ON DELETE SET NULL ON UPDATE CASCADE', $sql);
    }

    public function test_dialect_parameter_is_currently_ignored(): void
    {
        // ForeignKey emits identical ANSI-ish SQL for both mysql and pgsql.
        $mysql = (new ForeignKey('users', 'id'))->toSql('orders', 'user_id', 'mysql');
        $pgsql = (new ForeignKey('users', 'id'))->toSql('orders', 'user_id', 'pgsql');
        self::assertSame($mysql, $pgsql);
    }

    public function test_column_name_property_defaults_to_null(): void
    {
        // Used when the FK is rendered at the Table-level (constructor $foreignKeys).
        $fk = new ForeignKey('users', 'id');
        self::assertNull($fk->columnName);
    }

    public function test_column_name_stored_when_provided(): void
    {
        $fk = new ForeignKey('users', 'id', columnName: 'user_id');
        self::assertSame('user_id', $fk->columnName);
    }
}
