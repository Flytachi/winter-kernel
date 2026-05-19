<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping\Attributes\Hybrid;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Additive\DefaultVal;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Additive\NullableIs;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\BigId;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\Id;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\SmallId;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\UuidPk;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Primary;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\BigInteger;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Integer;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\SmallInteger;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Uuid;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Sub\AutoIncrement;
use PHPUnit\Framework\TestCase;

final class HybridTypesTest extends TestCase
{
    // ── Id → [Primary, AutoIncrement, NullableIs(false), Integer] ───────────

    public function test_id_expands_to_four_attributes_in_canonical_order(): void
    {
        $instances = (new Id())->getInstances('mysql');
        self::assertCount(4, $instances);
        self::assertInstanceOf(Primary::class, $instances[0]);
        self::assertInstanceOf(AutoIncrement::class, $instances[1]);
        self::assertInstanceOf(NullableIs::class, $instances[2]);
        self::assertInstanceOf(Integer::class, $instances[3]);
    }

    public function test_id_passes_always_flag_through_to_auto_increment(): void
    {
        // `always: true` propagates: AutoIncrement->toSql produces GENERATED ALWAYS form.
        $always = (new Id(always: true))->getInstances('pgsql');
        /** @var AutoIncrement $ai */
        $ai = $always[1];
        self::assertSame('INT GENERATED ALWAYS AS IDENTITY', $ai->toSql('INT', 'pgsql'));
    }

    // ── BigId — same shape, BigInteger underneath ────────────────────────────

    public function test_big_id_uses_big_integer(): void
    {
        $instances = (new BigId())->getInstances('mysql');
        self::assertInstanceOf(Primary::class, $instances[0]);
        self::assertInstanceOf(AutoIncrement::class, $instances[1]);
        self::assertInstanceOf(NullableIs::class, $instances[2]);
        self::assertInstanceOf(BigInteger::class, $instances[3]);
    }

    public function test_big_id_always_propagates(): void
    {
        $instances = (new BigId(always: true))->getInstances('pgsql');
        /** @var AutoIncrement $ai */
        $ai = $instances[1];
        self::assertSame('BIGINT GENERATED ALWAYS AS IDENTITY', $ai->toSql('BIGINT', 'pgsql'));
    }

    // ── SmallId — same shape, SmallInteger underneath ────────────────────────

    public function test_small_id_uses_small_integer(): void
    {
        $instances = (new SmallId())->getInstances('mysql');
        self::assertInstanceOf(SmallInteger::class, $instances[3]);
    }

    // ── UuidPk — different shape; dialect-aware default ─────────────────────

    public function test_uuid_pk_pgsql_uses_gen_random_uuid_default(): void
    {
        $instances = (new UuidPk())->getInstances('pgsql');
        self::assertCount(4, $instances);
        self::assertInstanceOf(Primary::class, $instances[0]);
        self::assertInstanceOf(Uuid::class, $instances[1]);
        self::assertInstanceOf(NullableIs::class, $instances[2]);
        self::assertInstanceOf(DefaultVal::class, $instances[3]);

        // Use the preparation byref hook to read the default the way ColumnMapping does.
        $nullable = null;
        $default = null;
        $instances[3]->preparation($nullable, $default);
        self::assertSame('gen_random_uuid()', $default);
    }

    public function test_uuid_pk_mysql_uses_uuid_function_default(): void
    {
        $instances = (new UuidPk())->getInstances('mysql');
        $nullable = null;
        $default = null;
        $instances[3]->preparation($nullable, $default);
        self::assertSame('UUID()', $default);
    }
}
