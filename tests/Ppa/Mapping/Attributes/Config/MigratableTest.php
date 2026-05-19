<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping\Attributes\Config;

use Attribute;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\AttributeDbConfig;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Config\Migratable;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\MigratablePriority;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;

#[Migratable]
class MigratableFixtureDefault
{
}

#[Migratable(priority: MigratablePriority::High)]
class MigratableFixtureHigh
{
}

#[Migratable(priority: MigratablePriority::Low)]
class MigratableFixtureLow
{
}

class MigratableFixtureNone
{
}

final class MigratableTest extends TestCase
{
    public function test_implements_attribute_db_config_marker(): void
    {
        self::assertInstanceOf(AttributeDbConfig::class, new Migratable());
    }

    public function test_default_priority_is_normal(): void
    {
        self::assertSame(MigratablePriority::Normal, (new Migratable())->priority);
    }

    public function test_explicit_priority_is_kept(): void
    {
        self::assertSame(MigratablePriority::High, (new Migratable(MigratablePriority::High))->priority);
        self::assertSame(MigratablePriority::Low, (new Migratable(MigratablePriority::Low))->priority);
    }

    // ── PHP attribute metadata ───────────────────────────────────────────────

    public function test_attribute_is_target_class_and_NOT_repeatable(): void
    {
        $ref = new ReflectionClass(Migratable::class);
        $attr = $ref->getAttributes(Attribute::class)[0] ?? null;
        self::assertNotNull($attr, 'Migratable must carry #[Attribute]');

        $flags = $attr->newInstance()->flags;
        self::assertSame(Attribute::TARGET_CLASS, $flags & Attribute::TARGET_CLASS);
        self::assertSame(0, $flags & Attribute::IS_REPEATABLE, 'Migratable must not be repeatable');
    }

    // ── Reflection on fixtures ───────────────────────────────────────────────

    public function test_reflection_reads_default_priority(): void
    {
        $attrs = (new ReflectionClass(MigratableFixtureDefault::class))
            ->getAttributes(Migratable::class, ReflectionAttribute::IS_INSTANCEOF);
        self::assertCount(1, $attrs);
        self::assertSame(MigratablePriority::Normal, $attrs[0]->newInstance()->priority);
    }

    public function test_reflection_reads_high_and_low_priority(): void
    {
        $high = (new ReflectionClass(MigratableFixtureHigh::class))
            ->getAttributes(Migratable::class, ReflectionAttribute::IS_INSTANCEOF);
        $low = (new ReflectionClass(MigratableFixtureLow::class))
            ->getAttributes(Migratable::class, ReflectionAttribute::IS_INSTANCEOF);

        self::assertSame(MigratablePriority::High, $high[0]->newInstance()->priority);
        self::assertSame(MigratablePriority::Low, $low[0]->newInstance()->priority);
    }

    public function test_reflection_returns_empty_when_no_attribute(): void
    {
        $attrs = (new ReflectionClass(MigratableFixtureNone::class))
            ->getAttributes(Migratable::class, ReflectionAttribute::IS_INSTANCEOF);
        self::assertSame([], $attrs);
    }
}
