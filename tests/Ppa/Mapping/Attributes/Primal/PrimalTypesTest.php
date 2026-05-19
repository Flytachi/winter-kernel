<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping\Attributes\Primal;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\AttributeDbType;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\BigInteger;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Binary;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Blob;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Boolean;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Char;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Date;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\DateTime;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Decimal;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Double;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\FloatType;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Integer;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Json;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\SmallInteger;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Text;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\TextArray;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Time;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Timestamp;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Type;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Uuid;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Varchar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Smoke coverage for every Primal AttributeDbType.
 * MySQL and MariaDB share PDO driver name 'mysql' — tested as the same row.
 */
final class PrimalTypesTest extends TestCase
{
    // ── Integer family — same supports() rule ────────────────────────────────

    /** @return array<string, array{0: AttributeDbType, 1: string}> */
    public static function intFamilyMysql(): array
    {
        return [
            'Integer'      => [new Integer(),      'INT'],
            'BigInteger'   => [new BigInteger(),   'BIGINT'],
            'SmallInteger' => [new SmallInteger(), 'SMALLINT'],
        ];
    }

    #[DataProvider('intFamilyMysql')]
    public function test_int_family_mysql(AttributeDbType $attr, string $expected): void
    {
        self::assertSame($expected, $attr->toSql('mysql'));
    }

    #[DataProvider('intFamilyMysql')]
    public function test_int_family_pgsql(AttributeDbType $attr, string $expected): void
    {
        // dialect-agnostic for int family
        self::assertSame($expected, $attr->toSql('pgsql'));
    }

    public function test_integer_supports_only_int_and_mixed(): void
    {
        $i = new Integer();
        self::assertTrue($i->supports(['int']));
        self::assertTrue($i->supports(['mixed']));
        self::assertTrue($i->supports(['int', 'null']));
        self::assertFalse($i->supports(['string']));
        self::assertFalse($i->supports(['int', 'string']));
        self::assertFalse($i->supports([]));
    }

    // ── Boolean ──────────────────────────────────────────────────────────────

    public function test_boolean_renders_BOOLEAN_for_both_dialects(): void
    {
        self::assertSame('BOOLEAN', (new Boolean())->toSql('mysql'));
        self::assertSame('BOOLEAN', (new Boolean())->toSql('pgsql'));
    }

    public function test_boolean_supports_bool_int_string_mixed(): void
    {
        $b = new Boolean();
        foreach (['bool', 'int', 'string', 'mixed'] as $t) {
            self::assertTrue($b->supports([$t]), "boolean should support {$t}");
        }
        self::assertFalse($b->supports(['array']));
        self::assertFalse($b->supports(['bool', 'string']));
    }

    // ── Varchar / Char / Text ───────────────────────────────────────────────

    public function test_varchar_default_length_is_255(): void
    {
        self::assertSame('VARCHAR(255)', (new Varchar())->toSql());
        self::assertSame('VARCHAR(50)', (new Varchar(50))->toSql());
    }

    public function test_varchar_length_zero_drops_paren(): void
    {
        // toSql guards on length > 0 — zero length emits bare VARCHAR (PG TEXT-like behaviour).
        self::assertSame('VARCHAR', (new Varchar(0))->toSql());
    }

    public function test_varchar_supports_anything(): void
    {
        self::assertTrue((new Varchar())->supports([]));
        self::assertTrue((new Varchar())->supports(['array']));
    }

    public function test_char_renders_with_length(): void
    {
        self::assertSame('CHAR(10)', (new Char(10))->toSql('mysql'));
        self::assertSame('CHAR(10)', (new Char(10))->toSql('pgsql'));
    }

    public function test_char_requires_positive_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Char(0);
    }

    public function test_char_supports_only_when_string_present(): void
    {
        $c = new Char(5);
        self::assertTrue($c->supports(['string']));
        self::assertTrue($c->supports(['string', 'null']));
        self::assertFalse($c->supports(['int']));
    }

    public function test_text_renders_TEXT_unconditional(): void
    {
        self::assertSame('TEXT', (new Text())->toSql('mysql'));
        self::assertSame('TEXT', (new Text())->toSql('pgsql'));
    }

    public function test_text_supports_anything(): void
    {
        self::assertTrue((new Text())->supports([]));
        self::assertTrue((new Text())->supports(['mixed']));
    }

    // ── Json / TextArray ─────────────────────────────────────────────────────

    public function test_json_emits_JSONB_on_pgsql_and_JSON_on_mysql(): void
    {
        self::assertSame('JSON', (new Json())->toSql('mysql'));
        self::assertSame('JSONB', (new Json())->toSql('pgsql'));
    }

    public function test_json_supports_string_array_mixed_and_combinations(): void
    {
        $j = new Json();
        self::assertTrue($j->supports(['string']));
        self::assertTrue($j->supports(['array']));
        self::assertTrue($j->supports(['mixed']));
        self::assertTrue($j->supports(['array', 'string']));
        self::assertFalse($j->supports(['int']));
        self::assertFalse($j->supports(['array', 'int']));
    }

    public function test_text_array_emits_pg_array_or_json_fallback(): void
    {
        self::assertSame('TEXT[]', (new TextArray())->toSql('pgsql'));
        self::assertSame('JSON', (new TextArray())->toSql('mysql'));
    }

    // ── Float family ─────────────────────────────────────────────────────────

    public function test_float_type(): void
    {
        self::assertSame('FLOAT', (new FloatType())->toSql('mysql'));
        self::assertSame('REAL', (new FloatType())->toSql('pgsql'));
    }

    public function test_double_type(): void
    {
        self::assertSame('DOUBLE', (new Double())->toSql('mysql'));
        self::assertSame('DOUBLE PRECISION', (new Double())->toSql('pgsql'));
    }

    public function test_decimal_with_precision_and_scale(): void
    {
        $d = new Decimal(precision: 10, scale: 4);
        self::assertSame('DECIMAL(10, 4)', $d->toSql('mysql'));
        self::assertSame('NUMERIC(10, 4)', $d->toSql('pgsql'));
    }

    public function test_decimal_defaults(): void
    {
        self::assertSame('DECIMAL(12, 2)', (new Decimal())->toSql('mysql'));
        self::assertSame('NUMERIC(12, 2)', (new Decimal())->toSql('pgsql'));
    }

    public function test_float_supports_int_float_string_mixed_and_combos(): void
    {
        $f = new FloatType();
        self::assertTrue($f->supports(['float']));
        self::assertTrue($f->supports(['int', 'float']));
        self::assertTrue($f->supports(['string']));
        self::assertFalse($f->supports(['array']));
    }

    // ── Date / Time / Timestamp / DateTime ──────────────────────────────────

    public function test_datetime(): void
    {
        self::assertSame('DATETIME', (new DateTime())->toSql('mysql'));
        self::assertSame('TIMESTAMP WITHOUT TIME ZONE', (new DateTime())->toSql('pgsql'));
    }

    public function test_date_overrides_to_DATE(): void
    {
        self::assertSame('DATE', (new Date())->toSql('mysql'));
        self::assertSame('DATE', (new Date())->toSql('pgsql'));
    }

    public function test_time_overrides_to_TIME(): void
    {
        self::assertSame('TIME', (new Time())->toSql('mysql'));
        self::assertSame('TIME', (new Time())->toSql('pgsql'));
    }

    public function test_timestamp_with_time_zone_default_true(): void
    {
        $ts = new Timestamp();
        self::assertSame('TIMESTAMP', $ts->toSql('mysql'));
        self::assertSame('TIMESTAMP WITH TIME ZONE', $ts->toSql('pgsql'));
        self::assertSame('DATETIME', $ts->toSql('sqlite'));
    }

    public function test_timestamp_without_time_zone(): void
    {
        $ts = new Timestamp(withTimeZone: false);
        self::assertSame('DATETIME', $ts->toSql('mysql'));
        self::assertSame('TIMESTAMP WITHOUT TIME ZONE', $ts->toSql('pgsql'));
    }

    public function test_datetime_supports_string_DateTime_and_DateTimeImmutable(): void
    {
        $dt = new DateTime();
        self::assertTrue($dt->supports(['string']));
        self::assertTrue($dt->supports(['\DateTime']));
        self::assertTrue($dt->supports(['\DateTimeImmutable']));
        self::assertTrue($dt->supports(['string', '\DateTime']));
        self::assertFalse($dt->supports(['int']));
    }

    // ── Binary / Blob ────────────────────────────────────────────────────────

    public function test_binary_dialect_specific(): void
    {
        self::assertSame('VARBINARY(255)', (new Binary())->toSql('mysql'));
        self::assertSame('BYTEA', (new Binary())->toSql('pgsql'));
        self::assertSame('BLOB', (new Binary())->toSql('sqlite'));
        // unknown dialect falls back to VARBINARY (mysql-style)
        self::assertSame('VARBINARY(64)', (new Binary(64))->toSql('oracle'));
    }

    public function test_binary_requires_positive_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Binary(0);
    }

    public function test_blob_sizes_mysql(): void
    {
        self::assertSame('BLOB', (new Blob())->toSql('mysql'));
        self::assertSame('TINYBLOB', (new Blob('tiny'))->toSql('mysql'));
        self::assertSame('MEDIUMBLOB', (new Blob('medium'))->toSql('mysql'));
        self::assertSame('LONGBLOB', (new Blob('long'))->toSql('mysql'));
    }

    public function test_blob_pgsql_always_BYTEA(): void
    {
        self::assertSame('BYTEA', (new Blob('long'))->toSql('pgsql'));
        self::assertSame('BYTEA', (new Blob())->toSql('pgsql'));
    }

    public function test_blob_rejects_unknown_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Blob('huge');
    }

    // ── Uuid ─────────────────────────────────────────────────────────────────

    public function test_uuid_dialect_matrix(): void
    {
        $u = new Uuid();
        self::assertSame('CHAR(36)', $u->toSql('mysql'));
        self::assertSame('UUID', $u->toSql('pgsql'));
        self::assertSame('TEXT', $u->toSql('sqlite'));
    }

    public function test_uuid_mysql_binary_form(): void
    {
        self::assertSame('BINARY(16)', (new Uuid(asBinary: true))->toSql('mysql'));
    }

    public function test_uuid_supports_string_only(): void
    {
        $u = new Uuid();
        self::assertTrue($u->supports(['string']));
        self::assertTrue($u->supports(['string', 'null']));
        self::assertFalse($u->supports(['int']));
    }

    // ── Type (escape hatch) ──────────────────────────────────────────────────

    public function test_type_returns_definition_verbatim_for_any_dialect(): void
    {
        $t = new Type('CITEXT');
        self::assertSame('CITEXT', $t->toSql('mysql'));
        self::assertSame('CITEXT', $t->toSql('pgsql'));
        self::assertSame('CITEXT', $t->toSql('any'));
    }

    public function test_type_supports_anything(): void
    {
        self::assertTrue((new Type('TEXT'))->supports([]));
        self::assertTrue((new Type('TEXT'))->supports(['mixed']));
    }
}
