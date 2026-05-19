<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Mapping\Attributes\Config;

use Attribute;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\AttributeDbConfig;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Config\Extension;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;

#[Extension('uuid-ossp')]
class ExtensionFixtureSingle
{
}

#[Extension('uuid-ossp')]
#[Extension('pgcrypto', cascade: true)]
#[Extension('postgis', version: '3.4', schema: 'gis')]
class ExtensionFixtureMultiple
{
}

class ExtensionFixtureNone
{
}

final class ExtensionTest extends TestCase
{
    public function test_implements_attribute_db_config_marker(): void
    {
        self::assertInstanceOf(AttributeDbConfig::class, new Extension('uuid-ossp'));
    }

    public function test_constructor_defaults(): void
    {
        $e = new Extension('uuid-ossp');
        self::assertSame('uuid-ossp', $e->name);
        self::assertNull($e->version);
        self::assertNull($e->schema);
        self::assertFalse($e->cascade);
    }

    public function test_constructor_named_args(): void
    {
        $e = new Extension('postgis', version: '3.4', schema: 'gis', cascade: true);
        self::assertSame('postgis', $e->name);
        self::assertSame('3.4', $e->version);
        self::assertSame('gis', $e->schema);
        self::assertTrue($e->cascade);
    }

    // ── PHP attribute metadata ───────────────────────────────────────────────

    public function test_attribute_is_target_class_and_repeatable(): void
    {
        $ref = new ReflectionClass(Extension::class);
        $attr = $ref->getAttributes(Attribute::class)[0] ?? null;
        self::assertNotNull($attr, 'Extension must carry #[Attribute]');

        $flags = $attr->newInstance()->flags;
        self::assertSame(Attribute::TARGET_CLASS, $flags & Attribute::TARGET_CLASS);
        self::assertSame(Attribute::IS_REPEATABLE, $flags & Attribute::IS_REPEATABLE);
    }

    // ── Reflection: stacking on a class works (IS_REPEATABLE) ────────────────

    public function test_reflection_finds_single_attribute(): void
    {
        $attrs = (new ReflectionClass(ExtensionFixtureSingle::class))
            ->getAttributes(Extension::class, ReflectionAttribute::IS_INSTANCEOF);
        self::assertCount(1, $attrs);
        self::assertSame('uuid-ossp', $attrs[0]->newInstance()->name);
    }

    public function test_reflection_finds_multiple_stacked_attributes_in_order(): void
    {
        $attrs = (new ReflectionClass(ExtensionFixtureMultiple::class))
            ->getAttributes(Extension::class, ReflectionAttribute::IS_INSTANCEOF);
        self::assertCount(3, $attrs);

        $names = array_map(static fn ($a): string => $a->newInstance()->name, $attrs);
        self::assertSame(['uuid-ossp', 'pgcrypto', 'postgis'], $names);
    }

    public function test_reflection_returns_empty_when_no_attribute_present(): void
    {
        $attrs = (new ReflectionClass(ExtensionFixtureNone::class))
            ->getAttributes(Extension::class, ReflectionAttribute::IS_INSTANCEOF);
        self::assertSame([], $attrs);
    }
}
