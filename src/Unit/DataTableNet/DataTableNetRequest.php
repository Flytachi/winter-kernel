<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\DataTableNet;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Kernel\Factory\Entity\RequestException;
use Flytachi\Winter\Kernel\Stereotype\RequestObject;
use Flytachi\Winter\Kernel\Unit\DataTableNet\Entity\DTNetColumn;
use Flytachi\Winter\Kernel\Unit\DataTableNet\Entity\DTNetColumns;
use Flytachi\Winter\Kernel\Unit\DataTableNet\Entity\DTNetOrder;
use Flytachi\Winter\Kernel\Unit\DataTableNet\Entity\DTNetSearch;

class DataTableNetRequest extends RequestObject
{
    /** @var callable|null User-defined callback for filtering logic */
    private $filterCallback = null;

    /** @var string|null Fallback ORDER BY string */
    private ?string $defaultOrder = null;

    public DTNetSearch $search;
    /* @var DTNetOrder[] $order */
    public array $order = [];
    public DTNetColumns $columns;

    public function __construct(
        public int $draw = 1,
        public int $start = 0,
        public int $length = 10,
        ?array $search = null,
        ?array $order = null,
        ?array $columns = null
    ) {
        $this->valid('draw', '\Flytachi\Kernel\Src\Unit\Tool::isIntPositive');
        $this->valid('start', 'is_numeric');
        $this->valid('length', '\Flytachi\Kernel\Src\Unit\Tool::isIntPositive');

        // Initialize search
        $this->search = match (true) {
            $search === null => new DTNetSearch(),
            default => new DTNetSearch($search['value'] ?? '', $search['regex'] ?? false),
        };

        // Initialize ordering
        if (!empty($order)) {
            foreach ($order as $orderItem) {
                $this->order[] = new DTNetOrder($orderItem['column'], $orderItem['dir']);
            }
        }

        // Initialize columns
        $this->columns = new DTNetColumns($columns ?? []);
    }

    /**
     * Validates that all requested columns are explicitly allowed.
     *
     * This method checks whether each column's `data` value is present in the given `$allowed` list.
     * If any column is not allowed, a {@see RequestException} is thrown.
     *
     * This is useful for whitelisting allowed fields before SQL generation.
     *
     * @param string[] $allowed List of permitted column `data` keys (e.g. ['id', 'name', 'status']).
     * @return void
     *
     * @throws RequestException If any column is not in the allowed list.
     *
     * @see overrideSelection()
     * @see selection()
     */
    public function allowColumns(array $allowed): void
    {
        foreach ($this->columns->items as $column) {
            if (!in_array($column->data, $allowed, true)) {
                throw new RequestException("Column '{$column->data}' is not allowed");
            }
        }
    }

    /**
     * Overrides column identifiers for use in the SQL SELECT clause.
     *
     * Sets the `name` property of each column based on the given map,
     * where keys are `data` field names and values are SQL-safe identifiers.
     *
     * Example:
     * ```
     *  $request->overrideSelection([
     *      'created' => 'created_at',
     *      'userName' => 'users.name'
     *  ]);
     * ```
     *
     * @param array<string, string> $resetNames Associative array of `data => name`.
     * @return void
     *
     * @see selection()
     */
    final public function overrideSelection(array $resetNames = []): void
    {
        foreach ($this->columns->items as $item) {
            if (isset($resetNames[$item->data])) {
                $item->name = $resetNames[$item->data];
            }
        }
    }

    /**
     * Sets a custom filtering callback for global and per-column search.
     *
     * The callback receives a `DTNetColumn` and a `string` value
     * and must return a `Qb|null` (or `null` to skip the column).
     *
     * Example:
     * ```
     *  $request->overrideFilter(function (DTNetColumn $col, string $value) {
     *      return Qb::like($col->data, "%{$value}%");
     *  });
     * ```
     *
     * @param callable|null $callback function(DTNetColumn $column, string $value): ?Qb
     * @return void
     *
     * @see filter()
     */
    final public function overrideFilter(?callable $callback): void
    {
        $this->filterCallback = $callback;
    }

    /**
     * Sets a fallback ORDER BY string used when no sorting is specified.
     *
     *  Example:
     *  ```
     *  $request->overrideOrder('created_at DESC')
     *  ```
     * @param string|null $defaultContext Example: "id DESC, created_at ASC"
     * @return void
     *
     * @see order()
     */
    final public function overrideOrder(?string $defaultContext = null): void
    {
        $this->defaultOrder = $defaultContext;
    }

    /**
     * Generates a comma-separated list of column names for a SQL SELECT clause.
     *
     * For each column, uses `name` if defined, otherwise `data`.
     *
     *  Example:
     *  - name: "t.id", data: "id" => "t.id AS id"
     *  - name: "id", data: "id" => "id"
     *
     * @return string Comma-separated list of SQL column identifiers.
     * @see overrideSelection()
     */
    final public function selection(): string
    {
        $naming = array_map(
            function (DTNetColumn $item): string {
                // If a name is defined and different from data, alias it
                if (!empty($item->name) && $item->name !== $item->data) {
                    return "{$item->name} AS {$item->data}";
                }
                // Use name if defined, otherwise fallback to data
                return $item->name ?: $item->data;
            },
            $this->columns->items
        );

        return implode(', ', $naming);
    }

    /**
     * Builds the SQL WHERE clause based on global and column-specific filters.
     *
     * - Global search is applied across all searchable columns (OR conditions).
     * - Column-specific search is applied using AND conditions.
     * - Uses the callback from {@see overrideFilter()} if defined, or `LIKE` by default.
     *
     * @return Qb A `Qb` WHERE condition or `Qb::empty()` if none.
     * @see overrideFilter()
     */
    final public function filter(): Qb
    {
        $andConditions = [];

        $callback = $this->filterCallback ?? function (DTNetColumn $column, string $value): ?Qb {
            $field = $column->name ?: $column->data;
            return Qb::like($field, "%{$value}%");
        };

        // Per-column search
        foreach ($this->columns->items as $column) {
            $value = trim($column->search->value ?? '');
            if ($value !== '' && $column->searchable) {
                $cond = $callback($column, $value);
                if ($cond !== null) {
                    $andConditions[] = $cond;
                }
            }
        }

        // Global search
        $global = trim($this->search->value ?? '');
        if ($global !== '') {
            $orConditions = [];

            foreach ($this->columns->items as $column) {
                if ($column->searchable) {
                    $cond = $callback($column, $global);
                    if ($cond !== null) {
                        $orConditions[] = $cond;
                    }
                }
            }

            if (!empty($orConditions)) {
                $andConditions[] = Qb::clip(Qb::or(...$orConditions));
            }
        }

        return empty($andConditions) ? Qb::empty() : Qb::and(...$andConditions);
    }

    /**
     * Builds a SQL ORDER BY expression from the current ordering configuration.
     *
     * Returns a comma-separated list like: "name ASC, created_at DESC".
     * If no valid order is found, returns a fallback set via {@see overrideOrder()}.
     *
     * @return string SQL ORDER BY clause without the "ORDER BY" keyword.
     * @see overrideOrder()
     */
    final public function order(): string
    {
        $orderClauses = [];

        foreach ($this->order as $orderItem) {
            /** @var DTNetOrder $orderItem */
            $column = $this->columns->items[$orderItem->column] ?? null;
            if (!$column || !$column->orderable) {
                continue;
            }

            $field = $column->name ?: $column->data;
            $direction = strtolower($orderItem->dir) === 'desc' ? 'DESC' : 'ASC';
            $orderClauses[] = "{$field} {$direction}";
        }

        if (empty($orderClauses) && $this->defaultOrder !== null) {
            return $this->defaultOrder;
        }

        return implode(', ', $orderClauses);
    }
}
