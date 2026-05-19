<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Crud;

use Flytachi\Winter\K2\Ppa\Stereotype\Repository;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;

/**
 * Shared infrastructure for any integration test that operates on the
 * `products` table. Owns schema lifecycle (CREATE TABLE in setUpBeforeClass,
 * TRUNCATE in setUp) and per-driver row inspection helpers.
 *
 * Concrete behaviour test bodies live in subclasses:
 *   - {@see CrudIntegrationTestCase} — insert / update / delete / upsert
 *   - {@see \Flytachi\Winter\K2\Tests\Integration\View\ViewIntegrationTestCase}
 *     — find / findAll / count / exists / *OrThrow
 *
 * Schema uses an explicit (non-auto-increment) integer PK; tests insert
 * rows with known ids and look them up by id — keeps assertions
 * deterministic across all three drivers.
 */
abstract class ProductsTableTestCase extends IntegrationTestCase
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

    /** @return list<array<string, mixed>> */
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
}
