<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Additive\DefaultVal;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Additive\NullableIs;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Index;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Unique;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal as P;
use Flytachi\Winter\K2\Ppa\Mapping\ColumnMapping;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Table;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The SQLite DDL is generated **and executed**, against a real in-memory database.
 *
 * Type mapping that merely renders is not evidence: SQLite accepts almost any type
 * name, so a wrong one shows up as broken behaviour rather than a syntax error. The
 * auto-increment column is the sharpest case — SQLite only treats a column as the
 * rowid alias when its type is spelled exactly `INTEGER`, and `INT` or `BIGINT` fail
 * at insert time with a NOT NULL violation, long after the schema was created.
 */
final class SqliteDdlTest extends TestCase
{
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
    }

    private function ddl(string $dialect = 'sqlite'): array
    {
        $map = new ColumnMapping($dialect);
        foreach ((new ReflectionClass(SqliteProduct::class))->getProperties() as $property) {
            $map->push($property);
        }

        $sql = (array) new Table('products', $map->getColumns())->toSql($dialect);

        return array_values(array_filter(array_map('trim', explode(';', implode(";\n", $sql)))));
    }

    private function migrated(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($this->ddl() as $statement) {
            $db->exec($statement);
        }

        return $db;
    }

    public function test_the_generated_schema_is_accepted(): void
    {
        // Any syntax the generator got wrong fails right here.
        $this->migrated();

        $this->addToAssertionCount(1);
    }

    public function test_the_identity_column_assigns_ids(): void
    {
        $db = $this->migrated();

        $db->exec("INSERT INTO products (sku, price, ratio) VALUES ('A', 1.5, 2.5)");
        $db->exec("INSERT INTO products (sku, price, ratio) VALUES ('B', 2.5, 3.5)");

        self::assertSame(
            ['1', '2'],
            array_map('strval', $db->query('SELECT id FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)),
            'the column must be a rowid alias, which requires the type to be exactly INTEGER',
        );
    }

    public function test_the_identity_column_is_declared_as_plain_integer(): void
    {
        $create = implode("\n", $this->ddl());

        self::assertMatchesRegularExpression('/\bid\s+INTEGER\b/', $create);
        self::assertStringNotContainsString('AUTO_INCREMENT', $create, 'that is MySQL syntax');
        self::assertStringNotContainsString('IDENTITY', $create, 'that is PostgreSQL syntax');
    }

    public function test_defaults_and_nullability_survive(): void
    {
        $db = $this->migrated();
        $db->exec("INSERT INTO products (sku, price, ratio) VALUES ('A', 1.5, 2.5)");

        $row = $db->query('SELECT stock, active, description FROM products')->fetch(PDO::FETCH_ASSOC);

        self::assertSame(0, (int) $row['stock']);
        self::assertSame(1, (int) $row['active']);
        self::assertNull($row['description']);
    }

    public function test_a_unique_index_is_enforced(): void
    {
        $db = $this->migrated();
        $db->exec("INSERT INTO products (sku, price, ratio) VALUES ('A', 1.5, 2.5)");

        $this->expectException(\PDOException::class);
        $db->exec("INSERT INTO products (sku, price, ratio) VALUES ('A', 9.9, 9.9)");
    }

    public function test_floating_types_no_longer_break_generation(): void
    {
        // Decimal, Double and Float used to raise UnhandledMatchError for any dialect
        // beyond mysql/pgsql, which stopped the migration before it reached the database.
        $create = implode("\n", $this->ddl());

        self::assertStringContainsString('NUMERIC(12, 2)', $create);
        self::assertStringContainsString('REAL', $create);
    }
}

/** Entity under test — kept next to the test since only this file uses it. */
final class SqliteProduct
{
    #[Id]
    public int $id;

    #[P\Varchar(120)]
    #[Unique]
    public string $sku;

    #[P\Text]
    #[NullableIs]
    public ?string $description;

    #[P\Decimal(12, 2)]
    public float $price;

    #[P\Double]
    public float $ratio;

    #[P\Integer]
    #[DefaultVal('0')]
    #[Index]
    public int $stock;

    #[P\Boolean]
    #[DefaultVal('TRUE')]
    public bool $active;
}
