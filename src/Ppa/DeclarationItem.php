<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\K2\Ppa\Mapping\Structure\Table;

/**
 * Holds a set of table structures associated with a single database configuration.
 *
 * Created and populated by {@see Declaration::push()}. Each item maps one
 * {@see DbConfigInterface} instance to one or more {@see Table} structures
 * that belong to that database.
 */
final class DeclarationItem
{
    /** @var Table[] Registered table structures for this configuration */
    private array $tables = [];

    /**
     * @param DbConfigInterface $config The database configuration this item belongs to
     */
    public function __construct(public readonly DbConfigInterface $config)
    {
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
}
