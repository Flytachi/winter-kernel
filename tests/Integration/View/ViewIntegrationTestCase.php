<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\View;

use Flytachi\Winter\Cdo\CDOBind;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Ppa\Entity\EntityException;
use Flytachi\Winter\Kernel\Tests\Integration\Crud\ProductsTableTestCase;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\ProductEntity;

/**
 * Shared base for read-side integration tests across pgsql / mysql / mariadb.
 *
 * Provisions the `products` table via {@see ProductsTableTestCase} and seeds
 * a deterministic 4-row dataset before each test method:
 *
 *     id | name    | price
 *      1 | alpha   | 1.00
 *      2 | beta    | 2.00
 *      3 | gamma   | NULL
 *      4 | delta   | 4.00
 *
 * Tests exercise the full {@see \Flytachi\Winter\Ppa\Repository\RepositoryViewTrait}
 * surface — find / findAll / findColumn / count / exists / static finders
 * / rawFetch / hydration.
 */
abstract class ViewIntegrationTestCase extends ProductsTableTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (self::$schemaName !== '') {
            self::seedProducts();
        }
    }

    protected static function seedProducts(): void
    {
        // Seed via raw PDO so the data layer doesn't accidentally test the
        // framework's CRUD code path before the view tests proper.
        $pdo = self::pdoOnTestSchema();
        $pdo->exec(
            "INSERT INTO products (id, name, price) VALUES "
            . "(1, 'alpha', 1.00), (2, 'beta', 2.00), (3, 'gamma', NULL), (4, 'delta', 4.00)"
        );
    }

    // ── find() ──────────────────────────────────────────────────────────────

    public function test_find_returns_first_matching_row_hydrated_as_entity(): void
    {
        $row = $this->repo()->where(Qb::eq('id', new CDOBind('i', 2)))->find();
        self::assertInstanceOf(ProductEntity::class, $row);
        self::assertSame(2, $row->id);
        self::assertSame('beta', $row->name);
    }

    public function test_find_returns_null_when_no_match(): void
    {
        $row = $this->repo()->where(Qb::eq('id', new CDOBind('i', 999)))->find();
        self::assertNull($row);
    }

    // ── findAll() ───────────────────────────────────────────────────────────

    public function test_findAll_returns_all_matching_rows(): void
    {
        $rows = $this->repo()
            ->where(Qb::gte('id', new CDOBind('lo', 2)))
            ->orderBy('id')
            ->findAll();

        self::assertCount(3, $rows);
        self::assertContainsOnlyInstancesOf(ProductEntity::class, $rows);
        self::assertSame(['beta', 'gamma', 'delta'], array_map(static fn ($r) => $r->name, $rows));
    }

    public function test_findAll_returns_empty_array_when_no_match(): void
    {
        $rows = $this->repo()->where(Qb::eq('id', new CDOBind('i', 999)))->findAll();
        self::assertSame([], $rows);
    }

    public function test_findAll_respects_order_and_limit(): void
    {
        $rows = $this->repo()->orderBy('id DESC')->limit(2)->findAll();
        self::assertCount(2, $rows);
        self::assertSame([4, 3], array_map(static fn ($r) => $r->id, $rows));
    }

    public function test_findAll_hydrates_nullable_columns_correctly(): void
    {
        $row = $this->repo()->where(Qb::eq('id', new CDOBind('i', 3)))->find();
        self::assertNotNull($row);
        self::assertSame('gamma', $row->name);
        self::assertNull($row->price);
    }

    // ── findColumn() ────────────────────────────────────────────────────────

    public function test_findColumn_returns_first_column_value(): void
    {
        // Typed repo: SELECT emits columns in ProductEntity property order
        // (id, name, price), so findColumn(0) returns the id of the matched row.
        $id = $this->repo()
            ->where(Qb::eq('name', new CDOBind('n', 'beta')))
            ->findColumn(0);
        self::assertSame(2, (int) $id);
    }

    // ── count() ─────────────────────────────────────────────────────────────

    public function test_count_returns_total_row_count_without_where(): void
    {
        self::assertSame(4, $this->repo()->count());
    }

    public function test_count_with_where_returns_matched_row_count(): void
    {
        $cnt = $this->repo()
            ->where(Qb::gte('id', new CDOBind('lo', 2)))
            ->count();
        self::assertSame(3, $cnt);
    }

    public function test_count_with_no_matches_returns_zero(): void
    {
        $cnt = $this->repo()
            ->where(Qb::eq('id', new CDOBind('i', 999)))
            ->count();
        self::assertSame(0, $cnt);
    }

    // ── exists() ────────────────────────────────────────────────────────────

    public function test_exists_returns_true_when_at_least_one_match(): void
    {
        self::assertTrue(
            $this->repo()->where(Qb::eq('id', new CDOBind('i', 2)))->exists(),
        );
    }

    public function test_exists_returns_false_when_no_match(): void
    {
        self::assertFalse(
            $this->repo()->where(Qb::eq('id', new CDOBind('i', 999)))->exists(),
        );
    }

    // ── Static finders ──────────────────────────────────────────────────────

    public function test_findById_static_returns_hydrated_entity(): void
    {
        $row = (static::repoClass())::findById(1);
        self::assertInstanceOf(ProductEntity::class, $row);
        self::assertSame('alpha', $row->name);
    }

    public function test_findById_static_returns_null_when_missing(): void
    {
        self::assertNull((static::repoClass())::findById(999));
    }

    public function test_findBy_static_uses_provided_condition(): void
    {
        $row = (static::repoClass())::findBy(Qb::eq('name', new CDOBind('n', 'delta')));
        self::assertNotNull($row);
        self::assertSame(4, $row->id);
    }

    public function test_findAllBy_static_with_condition_returns_matching(): void
    {
        $rows = (static::repoClass())::findAllBy(
            Qb::gte('id', new CDOBind('lo', 3)),
        );
        self::assertCount(2, $rows);
    }

    public function test_findAllBy_static_with_null_qb_returns_every_row(): void
    {
        $rows = (static::repoClass())::findAllBy(null);
        self::assertCount(4, $rows);
    }

    // ── *OrThrow variants ───────────────────────────────────────────────────

    public function test_findByIdOrThrow_returns_when_found(): void
    {
        $row = (static::repoClass())::findByIdOrThrow(1);
        self::assertInstanceOf(ProductEntity::class, $row);
    }

    public function test_findByIdOrThrow_throws_EntityException_when_missing(): void
    {
        $this->expectException(EntityException::class);
        (static::repoClass())::findByIdOrThrow(999);
    }

    public function test_findByOrThrow_returns_when_found(): void
    {
        $row = (static::repoClass())::findByOrThrow(
            Qb::eq('name', new CDOBind('n', 'alpha')),
        );
        self::assertSame(1, $row->id);
    }

    public function test_findByOrThrow_throws_when_no_match(): void
    {
        $this->expectException(EntityException::class);
        (static::repoClass())::findByOrThrow(
            Qb::eq('name', new CDOBind('n', 'nonexistent')),
        );
    }

    public function test_findByIdOrThrow_carries_custom_message(): void
    {
        try {
            (static::repoClass())::findByIdOrThrow(999, message: 'Product not found');
            self::fail('Expected EntityException');
        } catch (EntityException $e) {
            self::assertSame('Product not found', $e->getMessage());
        }
    }

    // ── rawFetch() ──────────────────────────────────────────────────────────
    //
    // rawFetch goes through the framework's CDO connection without setting
    // PG's search_path, so callers must schema-qualify table names — on PG
    // unqualified `products` resolves to `public.products`. Repository's
    // built-in queries handle this via originTable() (which returns
    // `<schema>.<table>` on PG and `<table>` on mysql/mariadb); tests mirror
    // that pattern by deriving the table name from the repo itself.

    public function test_rawFetch_executes_arbitrary_sql_and_hydrates(): void
    {
        $table = $this->repo()->originTable();
        $rows = $this->repo()->rawFetch(
            "SELECT id, name, price FROM {$table} WHERE id IN (1, 3) ORDER BY id",
        );
        self::assertCount(2, $rows);
        self::assertContainsOnlyInstancesOf(ProductEntity::class, $rows);
        self::assertSame(['alpha', 'gamma'], array_map(static fn ($r) => $r->name, $rows));
    }

    public function test_rawFetch_supports_named_binds(): void
    {
        $table = $this->repo()->originTable();
        $rows = $this->repo()->rawFetch(
            "SELECT id, name, price FROM {$table} WHERE id = :target",
            [new CDOBind('target', 4)],
        );
        self::assertCount(1, $rows);
        self::assertSame('delta', $rows[0]->name);
    }
}
