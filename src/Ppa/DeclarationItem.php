<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Config\Extension as ExtensionAttribute;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Config\Migratable;
use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\MigratablePriority;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Extension as ExtensionStructure;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Table;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Holds a set of table structures associated with a single database configuration.
 *
 * Created and populated by {@see Declaration::push()}. Each item maps one
 * {@see DbConfigInterface} instance to one or more {@see Table} structures
 * that belong to that database.
 *
 * On construction the item also scans the config class for
 * {@see ExtensionAttribute} attributes and exposes them via {@see getExtensions()}.
 */
final class DeclarationItem
{
    /** @var Table[] Registered table structures for this configuration */
    private array $tables = [];

    /** @var ExtensionStructure[] Database extensions declared on the config class */
    private array $extensions;

    /** @var ?Migratable Migratable attribute instance, null when the config does not opt in */
    private ?Migratable $migratable;

    /**
     * @param DbConfigInterface $config The database configuration this item belongs to
     */
    public function __construct(public readonly DbConfigInterface $config)
    {
        $ref = new ReflectionClass($config::class);
        $this->extensions = $this->collectExtensions($ref);
        $this->migratable = $this->collectMigratable($ref);
    }

    /**
     * Appends a table structure to this item.
     *
     * @param Table $newTable Table structure to register
     * @return void
     */
    public function push(Table $newTable): void
    {
        $this->tables[] = $newTable;
    }

    /**
     * Returns all registered table structures for this configuration.
     *
     * @return Table[]
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * Returns all extensions declared on the config class.
     *
     * @return ExtensionStructure[]
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * Whether the config class is opted into the migration tooling
     * (carries the {@see Migratable} attribute).
     */
    public function isMigratable(): bool
    {
        return $this->migratable !== null;
    }

    /**
     * Returns the migration priority declared by {@see Migratable}.
     * Defaults to {@see MigratablePriority::Normal} when the attribute is absent.
     */
    public function getPriority(): MigratablePriority
    {
        return $this->migratable?->priority ?? MigratablePriority::Normal;
    }

    /**
     * @return ExtensionStructure[]
     */
    private function collectExtensions(ReflectionClass $ref): array
    {
        $result = [];
        $seen = [];
        foreach ($ref->getAttributes(ExtensionAttribute::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            /** @var ExtensionAttribute $instance */
            $instance = $attr->newInstance();
            if (isset($seen[$instance->name])) {
                continue;
            }
            $seen[$instance->name] = true;
            $result[] = new ExtensionStructure(
                name: $instance->name,
                version: $instance->version,
                schema: $instance->schema,
                cascade: $instance->cascade,
            );
        }
        return $result;
    }

    private function collectMigratable(ReflectionClass $ref): ?Migratable
    {
        $attrs = $ref->getAttributes(Migratable::class, ReflectionAttribute::IS_INSTANCEOF);
        if (empty($attrs)) {
            return null;
        }
        /** @var Migratable */
        return $attrs[0]->newInstance();
    }
}
