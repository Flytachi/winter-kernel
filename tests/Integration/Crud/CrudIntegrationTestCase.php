<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Crud;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Qb;

/**
 * CRUD test bodies — insert / insertGroup / update / delete / upsert.
 *
 * Schema/lifecycle are owned by {@see ProductsTableTestCase}. Tests start
 * with an empty `products` table (setUp truncates) and insert rows with
 * explicit ids so assertions can look them up deterministically.
 *
 * winter-cdo ≥ 3.0.7 is required: `CDO::insert()` no longer throws on
 * `lastInsertId() === "0"` (which MySQL returns when the row was inserted
 * with an explicit value into a non-AUTO_INCREMENT column), and MariaDB
 * uses `INSERT ... RETURNING` to return the real id.
 */
abstract class CrudIntegrationTestCase extends ProductsTableTestCase
{
    // ── Shared test bodies ──────────────────────────────────────────────────

    public function test_insert_returns_value_per_driver(): void
    {
        // pgsql / mariadb: RETURNING <first key> → returns the inserted id (1).
        // mysql: lastInsertId() returns "0" because the PK is not AUTO_INCREMENT;
        //        CDO::insert() returns null in that case (no auto-id to expose).
        $result = $this->repo()->insert(['id' => 1, 'name' => 'widget', 'price' => 9.99]);

        if (static::driverFlavour() === 'mysql') {
            self::assertNull($result);
        } else {
            self::assertNotEmpty($result);
            self::assertSame(1, (int) $result);
        }
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
            ['id' => 1, 'name' => 'old',  'price' => 1.0],
            ['id' => 2, 'name' => 'keep', 'price' => 2.0],
        );

        $affected = $this->repo()->update(
            ['name' => 'new'],
            Qb::eq('id', new CDOBind('target', 1)),
        );
        self::assertSame(1, (int) $affected);

        self::assertSame('new',  $this->fetchProduct(1)['name']);
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
        // / "INSERT IGNORE" (mysql + mariadb) — existing row stays unchanged.
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
        // `updateColumns` is a map `[column => expression]` with DSL tokens:
        //   :new     → EXCLUDED.<col> (pgsql) / VALUES(<col>) (mysql + mariadb)
        //   :current → <table>.<col>  (pgsql) / <col>          (mysql + mariadb)
        $this->repo()->insert(['id' => 1, 'name' => 'first', 'price' => 1.0]);

        $this->repo()->upsert(
            ['id' => 1, 'name' => 'second', 'price' => 2.0],
            conflictColumns: ['id'],
            updateColumns: ['name' => ':new', 'price' => ':new'],
        );

        self::assertCount(1, $this->fetchAllProducts());
        self::assertSame('second', $this->fetchProduct(1)['name']);
        self::assertSame('2.00', (string) $this->fetchProduct(1)['price']);
    }
}
