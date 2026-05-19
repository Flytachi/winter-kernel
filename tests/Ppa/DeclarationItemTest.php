<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa;

use Flytachi\Winter\K2\Ppa\DeclarationItem;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Config\Extension;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Config\Migratable;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\MigratablePriority;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Extension as ExtensionStructure;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Table;
use Flytachi\Winter\K2\Tests\Ppa\Fixtures\StubDbConfig;
use PHPUnit\Framework\TestCase;

// ── Fixture configs ──────────────────────────────────────────────────────────
// DeclarationItem only reflects on the config CLASS — it never calls instance
// methods — so each fixture is a thin StubDbConfig subclass decorated with
// the attribute combos under test.

final class EmptyPgConfig extends StubDbConfig
{
    protected string $driver = 'pgsql';
}

final class EmptyMysqlConfig extends StubDbConfig
{
    protected string $driver = 'mysql'; // PDO reports both MySQL and MariaDB as 'mysql'
}

#[Extension('uuid-ossp')]
final class SingleExtPgConfig extends StubDbConfig
{
    protected string $driver = 'pgsql';
}

#[Extension('uuid-ossp')]
#[Extension('pgcrypto', cascade: true)]
#[Extension('postgis', version: '3.4', schema: 'gis')]
final class TripleExtPgConfig extends StubDbConfig
{
    protected string $driver = 'pgsql';
}

#[Extension('uuid-ossp')]
#[Extension('uuid-ossp', cascade: true)]
#[Extension('pgcrypto')]
final class DuplicateExtPgConfig extends StubDbConfig
{
    protected string $driver = 'pgsql';
}

// Mysql config carrying an Extension attribute — DeclarationItem must still
// collect it (driver-agnostic), only the SQL emitter gates on dialect.
#[Extension('uuid-ossp')]
final class ExtOnMysqlConfig extends StubDbConfig
{
    protected string $driver = 'mysql';
}

#[Migratable]
final class MigratableDefaultPgConfig extends StubDbConfig
{
    protected string $driver = 'pgsql';
}

#[Migratable(priority: MigratablePriority::High)]
final class MigratableHighPgConfig extends StubDbConfig
{
    protected string $driver = 'pgsql';
}

#[Migratable(priority: MigratablePriority::Low)]
final class MigratableLowMysqlConfig extends StubDbConfig
{
    protected string $driver = 'mysql';
}

#[Migratable]
#[Extension('uuid-ossp')]
#[Extension('pgcrypto', cascade: true)]
final class FullPgConfig extends StubDbConfig
{
    protected string $driver = 'pgsql';
}

// ── Tests ────────────────────────────────────────────────────────────────────

final class DeclarationItemTest extends TestCase
{
    // ── Config storage ───────────────────────────────────────────────────────

    public function test_constructor_stores_config_reference(): void
    {
        $cfg = new EmptyPgConfig();
        $item = new DeclarationItem($cfg);
        self::assertSame($cfg, $item->config);
    }

    public function test_tables_initially_empty(): void
    {
        self::assertSame([], (new DeclarationItem(new EmptyPgConfig()))->getTables());
    }

    public function test_push_appends_tables_in_order(): void
    {
        $item = new DeclarationItem(new EmptyPgConfig());
        $a = new Table('users', columns: []);
        $b = new Table('orders', columns: []);

        $item->push($a);
        $item->push($b);

        self::assertSame([$a, $b], $item->getTables());
    }

    // ── Extension collection ─────────────────────────────────────────────────

    public function test_no_extensions_when_attribute_absent(): void
    {
        self::assertSame([], (new DeclarationItem(new EmptyPgConfig()))->getExtensions());
    }

    public function test_single_extension_collected(): void
    {
        $exts = (new DeclarationItem(new SingleExtPgConfig()))->getExtensions();
        self::assertCount(1, $exts);
        self::assertInstanceOf(ExtensionStructure::class, $exts[0]);
        self::assertSame('uuid-ossp', $exts[0]->name);
        self::assertNull($exts[0]->version);
        self::assertNull($exts[0]->schema);
        self::assertFalse($exts[0]->cascade);
    }

    public function test_multiple_extensions_preserve_declaration_order_and_params(): void
    {
        $exts = (new DeclarationItem(new TripleExtPgConfig()))->getExtensions();
        self::assertCount(3, $exts);

        self::assertSame('uuid-ossp', $exts[0]->name);
        self::assertFalse($exts[0]->cascade);

        self::assertSame('pgcrypto', $exts[1]->name);
        self::assertTrue($exts[1]->cascade);

        self::assertSame('postgis', $exts[2]->name);
        self::assertSame('3.4', $exts[2]->version);
        self::assertSame('gis', $exts[2]->schema);
    }

    public function test_duplicate_extensions_deduped_by_name_first_wins(): void
    {
        $exts = (new DeclarationItem(new DuplicateExtPgConfig()))->getExtensions();
        self::assertCount(2, $exts);

        // first uuid-ossp kept (cascade=false from first declaration)
        self::assertSame('uuid-ossp', $exts[0]->name);
        self::assertFalse($exts[0]->cascade);

        self::assertSame('pgcrypto', $exts[1]->name);
    }

    public function test_extensions_are_collected_regardless_of_driver(): void
    {
        // DeclarationItem is driver-agnostic. Whether an Extension is actually
        // emitted as SQL is the Db command's concern (gated on pgsql).
        $exts = (new DeclarationItem(new ExtOnMysqlConfig()))->getExtensions();
        self::assertCount(1, $exts);
        self::assertSame('uuid-ossp', $exts[0]->name);
    }

    // ── Migratable collection ────────────────────────────────────────────────

    public function test_is_migratable_false_when_attribute_absent(): void
    {
        self::assertFalse((new DeclarationItem(new EmptyPgConfig()))->isMigratable());
        self::assertFalse((new DeclarationItem(new EmptyMysqlConfig()))->isMigratable());
    }

    public function test_priority_defaults_to_normal_when_attribute_absent(): void
    {
        self::assertSame(
            MigratablePriority::Normal,
            (new DeclarationItem(new EmptyPgConfig()))->getPriority(),
        );
    }

    public function test_is_migratable_true_when_attribute_present_pgsql(): void
    {
        self::assertTrue((new DeclarationItem(new MigratableDefaultPgConfig()))->isMigratable());
    }

    public function test_is_migratable_true_when_attribute_present_mysql(): void
    {
        // Migratable is driver-agnostic — MySQL/MariaDB participate identically.
        self::assertTrue((new DeclarationItem(new MigratableLowMysqlConfig()))->isMigratable());
    }

    public function test_priority_default_attribute_is_normal(): void
    {
        self::assertSame(
            MigratablePriority::Normal,
            (new DeclarationItem(new MigratableDefaultPgConfig()))->getPriority(),
        );
    }

    public function test_priority_high_and_low_are_read_back(): void
    {
        self::assertSame(
            MigratablePriority::High,
            (new DeclarationItem(new MigratableHighPgConfig()))->getPriority(),
        );
        self::assertSame(
            MigratablePriority::Low,
            (new DeclarationItem(new MigratableLowMysqlConfig()))->getPriority(),
        );
    }

    // ── Combined attributes ──────────────────────────────────────────────────

    public function test_full_config_reads_both_extensions_and_migratable(): void
    {
        $item = new DeclarationItem(new FullPgConfig());

        self::assertTrue($item->isMigratable());
        self::assertSame(MigratablePriority::Normal, $item->getPriority());

        $exts = $item->getExtensions();
        self::assertCount(2, $exts);
        self::assertSame('uuid-ossp', $exts[0]->name);
        self::assertSame('pgcrypto', $exts[1]->name);
        self::assertTrue($exts[1]->cascade);
    }

    // ── Sort behaviour (used by Db command) ──────────────────────────────────

    public function test_priority_value_orders_high_before_normal_before_low(): void
    {
        $items = [
            new DeclarationItem(new MigratableLowMysqlConfig()),       // Low
            new DeclarationItem(new MigratableDefaultPgConfig()),      // Normal
            new DeclarationItem(new MigratableHighPgConfig()),         // High
        ];

        usort(
            $items,
            static fn (DeclarationItem $a, DeclarationItem $b): int
                => $a->getPriority()->value <=> $b->getPriority()->value,
        );

        $priorities = array_map(static fn ($i) => $i->getPriority(), $items);
        self::assertSame(
            [MigratablePriority::High, MigratablePriority::Normal, MigratablePriority::Low],
            $priorities,
        );
    }
}
