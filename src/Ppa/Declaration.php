<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa;

use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Kernel\Ppa\Mapping\Structure\Table;

/**
 * Registry of database structure declarations grouped by configuration.
 *
 * Collects {@see Table} structures per {@see DbConfigInterface} class.
 * Multiple {@see push()} calls for the same config class are merged into
 * a single {@see DeclarationItem}, so one item always corresponds to one database.
 *
 * Usage:
 * ```
 * $declaration = new Declaration();
 * $declaration->push($dbConfig, $usersTable);
 * $declaration->push($dbConfig, $ordersTable); // merged into same item
 *
 * foreach ($declaration->getItems() as $item) {
 *     foreach ($item->getTables() as $table) {
 *         echo $table->toSql();
 *     }
 * }
 * ```
 */
final class Declaration
{
    /** @var DeclarationItem[] */
    private array $items;

    /**
     * Initialises an empty declaration registry.
     */
    public function __construct()
    {
        $this->items = [];
    }

    /**
     * Registers a table structure under the given database configuration.
     *
     * If an item for the same config class already exists, the table is appended
     * to it. Otherwise a new {@see DeclarationItem} is created.
     *
     * @param DbConfigInterface $config         Database configuration instance
     * @param Table             $structureTable Table structure to register
     * @return void
     */
    public function push(DbConfigInterface $config, Table $structureTable): void
    {
        foreach ($this->items as $item) {
            if ($item->config::class === $config::class) {
                $item->push($structureTable);
                return;
            }
        }
        $newItem = new DeclarationItem($config);
        $newItem->push($structureTable);
        $this->items[] = $newItem;
    }

    /**
     * Returns all registered declaration items.
     *
     * @return DeclarationItem[]
     */
    public function getItems(): array
    {
        return $this->items;
    }
}
