<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa;

use Flytachi\Winter\K2\Ppa\Declaration;
use Flytachi\Winter\K2\Ppa\DeclarationItem;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Column;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Table;
use Flytachi\Winter\K2\Tests\Ppa\Fixtures\StubDbConfig;
use PHPUnit\Framework\TestCase;

final class DeclConfigAlpha extends StubDbConfig
{
    protected string $driver = 'pgsql';
}

final class DeclConfigBeta extends StubDbConfig
{
    protected string $driver = 'mysql';
}

final class DeclarationTest extends TestCase
{
    public function test_empty_declaration_has_no_items(): void
    {
        self::assertSame([], (new Declaration())->getItems());
    }

    public function test_push_creates_item_per_config_class(): void
    {
        $decl = new Declaration();
        $decl->push(new DeclConfigAlpha(), new Table('users', columns: []));
        $decl->push(new DeclConfigBeta(), new Table('audit_log', columns: []));

        self::assertCount(2, $decl->getItems());
    }

    public function test_push_merges_tables_into_existing_item_for_same_config_class(): void
    {
        $decl = new Declaration();
        $decl->push(new DeclConfigAlpha(), new Table('users', columns: []));
        $decl->push(new DeclConfigAlpha(), new Table('orders', columns: []));

        // Same config class → one item with two tables.
        self::assertCount(1, $decl->getItems());

        $tables = $decl->getItems()[0]->getTables();
        self::assertCount(2, $tables);
        self::assertSame('users', $tables[0]->name);
        self::assertSame('orders', $tables[1]->name);
    }

    public function test_distinct_instances_of_same_class_still_merge(): void
    {
        // Declaration::push() deduplicates by class name, not by instance —
        // so two different instances of DeclConfigAlpha go into the same item.
        $decl = new Declaration();
        $decl->push(new DeclConfigAlpha(), new Table('users', columns: []));
        $decl->push(new DeclConfigAlpha(), new Table('roles', columns: []));

        self::assertCount(1, $decl->getItems());
        self::assertCount(2, $decl->getItems()[0]->getTables());
    }

    public function test_first_pushed_config_instance_is_the_one_retained_in_item(): void
    {
        $first = new DeclConfigAlpha();
        $second = new DeclConfigAlpha();

        $decl = new Declaration();
        $decl->push($first, new Table('users', columns: []));
        $decl->push($second, new Table('orders', columns: []));

        self::assertSame($first, $decl->getItems()[0]->config);
        self::assertNotSame($second, $decl->getItems()[0]->config);
    }

    public function test_items_preserve_push_order_across_distinct_configs(): void
    {
        $decl = new Declaration();
        $decl->push(new DeclConfigBeta(), new Table('audit_log', columns: []));
        $decl->push(new DeclConfigAlpha(), new Table('users', columns: []));

        $items = $decl->getItems();
        self::assertInstanceOf(DeclConfigBeta::class, $items[0]->config);
        self::assertInstanceOf(DeclConfigAlpha::class, $items[1]->config);
    }

    public function test_get_items_returns_declaration_item_instances(): void
    {
        $decl = new Declaration();
        $decl->push(new DeclConfigAlpha(), new Table('users', columns: [new Column('id', 'INT')]));
        self::assertContainsOnlyInstancesOf(DeclarationItem::class, $decl->getItems());
    }
}
