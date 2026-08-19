<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Types;

use Flytachi\Winter\Ppa\Stereotype\Repository;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\IntegrationTestCase;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\SpecimenEntity;

/**
 * Verifies the framework's Mapping layer end-to-end across pgsql / mysql / mariadb:
 *
 *   PHP value → Repository::insert
 *               → CDOStatement::bindTypedValue (per-type PDO::PARAM_*)
 *               → driver
 *               ← PDO::FETCH_CLASS into SpecimenEntity (typed properties)
 *               ← Repository::findById  → assertion
 *
 * We do NOT assert against raw PDO output — PDO's type behaviour is upstream
 * and out of scope. What the framework owns:
 *   - bindTypedValue mapping (bool → PARAM_BOOL, array → JSON-encoded, …)
 *   - CDO::insert filtering null values out of the payload
 *   - Repository::find hydration into the configured entity class
 *
 * Each test inserts via the repo, fetches via `findById`, and reads the
 * hydrated entity — the path users actually take.
 */
abstract class TypesIntegrationTestCase extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$schemaName !== '') {
            self::createSpecimensTable();
        }
    }

    protected function setUp(): void
    {
        if (self::$schemaName !== '') {
            self::truncateSpecimens();
        }
    }

    /** @return class-string<Repository> */
    abstract protected static function repoClass(): string;

    protected function repo(): Repository
    {
        /** @var Repository */
        return new (static::repoClass())();
    }

    protected function findOne(int $id): ?SpecimenEntity
    {
        /** @var SpecimenEntity|null */
        return (static::repoClass())::findById($id);
    }

    protected static function createSpecimensTable(): void
    {
        $pdo = self::pdoOnTestSchema();
        $ddl = match (static::driverFlavour()) {
            'pgsql' => 'CREATE TABLE specimens (
                id        INTEGER NOT NULL PRIMARY KEY,
                str_col   VARCHAR(255),
                int_col   INTEGER,
                bool_col  BOOLEAN,
                dec_col   NUMERIC(10, 2),
                ts_col    TIMESTAMP WITHOUT TIME ZONE,
                json_col  JSONB,
                uuid_col  UUID,
                text_col  TEXT
            )',
            'mysql', 'mariadb' => 'CREATE TABLE specimens (
                id        INT NOT NULL PRIMARY KEY,
                str_col   VARCHAR(255),
                int_col   INT,
                bool_col  BOOLEAN,
                dec_col   DECIMAL(10, 2),
                ts_col    DATETIME,
                json_col  JSON,
                uuid_col  CHAR(36),
                text_col  TEXT
            )',
        };
        $pdo->exec($ddl);
    }

    protected static function truncateSpecimens(): void
    {
        self::pdoOnTestSchema()->exec('TRUNCATE TABLE specimens');
    }

    // ── Strings / numbers — straight-through typed hydration ────────────────

    public function test_varchar_hydrates_into_string_property(): void
    {
        $this->repo()->insert(['id' => 1, 'str_col' => 'hello world']);
        self::assertSame('hello world', $this->findOne(1)->str_col);
    }

    public function test_int_hydrates_into_int_property(): void
    {
        $this->repo()->insert(['id' => 1, 'int_col' => 42]);
        self::assertSame(42, $this->findOne(1)->int_col);
    }

    public function test_decimal_keeps_scale_as_string(): void
    {
        // NUMERIC/DECIMAL comes back as string so precision is not lost.
        // Entity declares `?string $dec_col` — framework hands the raw value
        // through to the property untouched.
        $this->repo()->insert(['id' => 1, 'dec_col' => '99.99']);
        self::assertSame('99.99', $this->findOne(1)->dec_col);
    }

    public function test_large_text_roundtrip(): void
    {
        $payload = str_repeat('lorem-ipsum-', 200);
        $this->repo()->insert(['id' => 1, 'text_col' => $payload]);
        self::assertSame($payload, $this->findOne(1)->text_col);
    }

    // ── Booleans — exercises bindTypedValue's PARAM_BOOL mapping + ?bool
    //    typed-property coercion on hydration ─────────────────────────────────

    public function test_bool_true_roundtrip_via_typed_property(): void
    {
        $this->repo()->insert(['id' => 1, 'bool_col' => true]);
        self::assertTrue($this->findOne(1)->bool_col);
    }

    public function test_bool_false_roundtrip_via_typed_property(): void
    {
        $this->repo()->insert(['id' => 1, 'bool_col' => false]);
        self::assertFalse($this->findOne(1)->bool_col);
    }

    // ── DateTime — string roundtrip; framework doesn't coerce DateTime objects
    //    (that would be a separate feature) ──────────────────────────────────

    public function test_datetime_string_roundtrip(): void
    {
        $this->repo()->insert(['id' => 1, 'ts_col' => '2024-01-15 10:30:00']);
        self::assertSame('2024-01-15 10:30:00', $this->findOne(1)->ts_col);
    }

    // ── JSON — two paths: caller-supplied string, OR framework auto-encoding
    //    of a PHP array via bindTypedValue ────────────────────────────────────

    public function test_json_string_supplied_by_caller_roundtrips(): void
    {
        $payload = '{"name":"Alice","age":30,"tags":["admin","power"]}';
        $this->repo()->insert(['id' => 1, 'json_col' => $payload]);

        $entity = $this->findOne(1);
        self::assertNotNull($entity->json_col);
        $decoded = json_decode($entity->json_col, true, flags: JSON_THROW_ON_ERROR);
        // PG JSONB and MySQL JSON normalise key order during storage —
        // canonicalise the comparison so structural equivalence is what's asserted.
        self::assertEqualsCanonicalizing(
            ['name' => 'Alice', 'age' => 30, 'tags' => ['admin', 'power']],
            $decoded,
        );
    }

    public function test_php_array_is_auto_json_encoded_by_bindTypedValue(): void
    {
        // bindTypedValue maps PHP `array` → JSON-encoded string + PARAM_STR.
        // This is framework behaviour, not PDO behaviour — the test pins it.
        $this->repo()->insert([
            'id' => 1,
            'json_col' => ['name' => 'Bob', 'roles' => ['user']],
        ]);

        $entity = $this->findOne(1);
        self::assertNotNull($entity->json_col);
        $decoded = json_decode($entity->json_col, true, flags: JSON_THROW_ON_ERROR);
        self::assertEqualsCanonicalizing(
            ['name' => 'Bob', 'roles' => ['user']],
            $decoded,
        );
    }

    // ── UUID ────────────────────────────────────────────────────────────────

    public function test_uuid_string_roundtrip(): void
    {
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $this->repo()->insert(['id' => 1, 'uuid_col' => $uuid]);

        $entity = $this->findOne(1);
        // PG's UUID type normalises to lowercase; CHAR(36) preserves input as-is.
        self::assertSame($uuid, strtolower((string) $entity->uuid_col));
    }

    // ── Nullability — framework filtering behaviour ─────────────────────────

    public function test_null_values_filtered_from_insert_by_framework(): void
    {
        // CDO::insert strips null values BEFORE the SQL is built.
        // After fetch, the typed property reflects the column's stored NULL.
        $this->repo()->insert(['id' => 1, 'str_col' => 'present', 'int_col' => null]);

        $entity = $this->findOne(1);
        self::assertSame('present', $entity->str_col);
        self::assertNull($entity->int_col);
    }

    public function test_unset_nullable_columns_hydrate_as_null(): void
    {
        $this->repo()->insert(['id' => 1, 'str_col' => 'only-this']);

        $entity = $this->findOne(1);
        self::assertNull($entity->int_col);
        self::assertNull($entity->bool_col);
        self::assertNull($entity->dec_col);
        self::assertNull($entity->ts_col);
        self::assertNull($entity->json_col);
        self::assertNull($entity->uuid_col);
        self::assertNull($entity->text_col);
    }
}
