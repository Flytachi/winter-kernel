<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Crud;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\K2\Ppa\Stereotype\Repository;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;

/**
 * Shared base for CRUD integration tests across pgsql / mysql / mariadb.
 *
 * Lifecycle:
 * - setUpBeforeClass (inherited) creates the per-class schema/database.
 * - This class then provisions a `products` table inside it.
 * - setUp TRUNCATEs the table so every test starts with an empty slate.
 *
 * Subclasses pick the concrete Repository class via {@see repoClass()};
 * the assertions are dialect-agnostic and shared.
 */
abstract class CrudIntegrationTestCase extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$schemaName !== '') {
            self::createProductsTable();
        }
    }

    protected function setUp(): void
    {
        if (self::$schemaName !== '') {
            self::truncateProducts();
        }
    }

    /** @return class-string<Repository> */
    abstract protected static function repoClass(): string;

    protected function repo(): Repository
    {
        /** @var Repository */
        return new (static::repoClass())();
    }

    protected static function createProductsTable(): void
    {
        $pdo = self::pdoOnTestSchema();
        $ddl = match (static::driverFlavour()) {
            'pgsql' => 'CREATE TABLE products (
                id    INTEGER NOT NULL PRIMARY KEY,
                name  VARCHAR(255) NOT NULL,
                price NUMERIC(10, 2)
            )',
            'mysql', 'mariadb' => 'CREATE TABLE products (
                id    INT NOT NULL PRIMARY KEY,
                name  VARCHAR(255) NOT NULL,
                price DECIMAL(10, 2)
            )',
        };
        $pdo->exec($ddl);
    }

    protected static function truncateProducts(): void
    {
        self::pdoOnTestSchema()->exec('TRUNCATE TABLE products');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchAllProducts(): array
    {
        $stmt = self::pdoOnTestSchema()->query('SELECT id, name, price FROM products ORDER BY id');
        return array_map(
            static fn (array $r) => array_change_key_case($r),
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    protected function fetchProduct(int $id): ?array
    {
        $stmt = self::pdoOnTestSchema()->prepare('SELECT id, name, price FROM products WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : array_change_key_case($row);
    }

    // ── Shared test bodies (run for every concrete subclass) ─────────────────

    public function test_insert_returns_truthy_identifier(): void
    {
        $id = $this->repo()->insert(['id' => 1, 'name' => 'widget', 'price' => 9.99]);
        self::assertNotEmpty($id);
    }

    public function test_insert_persists_row(): void
    {
        $this->repo()->insert(['id' => 1, 'name' => 'widget', 'price' => 9.99]);

        $row = $this->fetchProduct(1);
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['id']);
        self::assertSame('widget', $row['name']);
        self::assertSame('9.99', (string) $row['price']);
    }

    public function test_insert_omits_null_columns(): void
    {
        // CrudTrait::insert filters null values — `price` not in INSERT, defaults to NULL.
        $this->repo()->insert(['id' => 2, 'name' => 'gizmo', 'price' => null]);

        $row = $this->fetchProduct(2);
        self::assertNotNull($row);
        self::assertNull($row['price']);
    }

    public function test_insert_group_creates_all_rows(): void
    {
        $this->repo()->insertGroup(
            ['id' => 1, 'name' => 'a', 'price' => 1.0],
            ['id' => 2, 'name' => 'b', 'price' => 2.0],
            ['id' => 3, 'name' => 'c', 'price' => 3.0],
        );

        $rows = $this->fetchAllProducts();
        self::assertCount(3, $rows);
        self::assertSame(['a', 'b', 'c'], array_column($rows, 'name'));
    }

    public function test_update_changes_matched_rows_only(): void
    {
        $this->repo()->insertGroup(
            ['id' => 1, 'name' => 'old', 'price' => 1.0],
            ['id' => 2, 'name' => 'keep', 'price' => 2.0],
        );

        $affected = $this->repo()->update(
            ['name' => 'new'],
            Qb::eq('id', new CDOBind('target', 1)),
        );
        self::assertSame(1, (int) $affected);

        self::assertSame('new', $this->fetchProduct(1)['name']);
        self::assertSame('keep', $this->fetchProduct(2)['name']);
    }

    public function test_delete_removes_only_matched_rows(): void
    {
        $this->repo()->insertGroup(
            ['id' => 1, 'name' => 'a', 'price' => 1.0],
            ['id' => 2, 'name' => 'b', 'price' => 2.0],
            ['id' => 3, 'name' => 'c', 'price' => 3.0],
        );

        $affected = $this->repo()->delete(Qb::eq('id', new CDOBind('victim', 2)));
        self::assertSame(1, (int) $affected);

        $rows = $this->fetchAllProducts();
        self::assertCount(2, $rows);
        self::assertSame([1, 3], array_map(static fn ($r) => (int) $r['id'], $rows));
    }

    public function test_upsert_inserts_new_row_when_no_conflict(): void
    {
        $this->repo()->upsert(
            ['id' => 1, 'name' => 'fresh', 'price' => 1.5],
            conflictColumns: ['id'],
        );

        $row = $this->fetchProduct(1);
        self::assertNotNull($row);
        self::assertSame('fresh', $row['name']);
    }

    public function test_upsert_without_updateColumns_does_nothing_on_conflict(): void
    {
        // CDO::upsert with $updateColumns=null emits "ON CONFLICT DO NOTHING" (pgsql)
        // / "INSERT IGNORE" (mysql) — the existing row stays unchanged.
        $this->repo()->insert(['id' => 1, 'name' => 'first', 'price' => 1.0]);

        $this->repo()->upsert(
            ['id' => 1, 'name' => 'second', 'price' => 2.0],
            conflictColumns: ['id'],
        );

        self::assertCount(1, $this->fetchAllProducts());
        self::assertSame('first', $this->fetchProduct(1)['name']);
    }

    public function test_upsert_with_updateColumns_updates_existing_row(): void
    {
        // Passing $updateColumns switches to "ON CONFLICT DO UPDATE SET …" (pgsql)
        // / "ON DUPLICATE KEY UPDATE …" (mysql) — the matched row is overwritten.
        $this->repo()->insert(['id' => 1, 'name' => 'first', 'price' => 1.0]);

        $this->repo()->upsert(
            ['id' => 1, 'name' => 'second', 'price' => 2.0],
            conflictColumns: ['id'],
            updateColumns: ['name', 'price'],
        );

        self::assertCount(1, $this->fetchAllProducts());
        self::assertSame('second', $this->fetchProduct(1)['name']);
        self::assertSame('2.00', (string) $this->fetchProduct(1)['price']);
    }
}
