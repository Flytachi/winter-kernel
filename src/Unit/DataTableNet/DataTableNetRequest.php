<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\DataTableNet;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\K2\Http\Request\RequestException;
use Flytachi\Winter\K2\Http\Request\RequestObject;
use Flytachi\Winter\K2\Unit\DataTableNet\Entity\DTNetColumn;
use Flytachi\Winter\K2\Unit\DataTableNet\Entity\DTNetColumns;
use Flytachi\Winter\K2\Unit\DataTableNet\Entity\DTNetOrder;
use Flytachi\Winter\K2\Unit\DataTableNet\Entity\DTNetSearch;

class DataTableNetRequest extends RequestObject
{
    /** @var callable|null User-defined callback for filtering logic */
    private $filterCallback = null;

    /** @var string|null Fallback ORDER BY string */
    private ?string $defaultOrder = null;

    public DTNetSearch $search;
    /** @var DTNetOrder[] */
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
        $this->search = match (true) {
            $search === null => new DTNetSearch(),
            default => new DTNetSearch($search['value'] ?? '', $search['regex'] ?? false),
        };

        if (!empty($order)) {
            foreach ($order as $orderItem) {
                $this->order[] = new DTNetOrder($orderItem['column'], $orderItem['dir']);
            }
        }

        $this->columns = new DTNetColumns($columns ?? []);
    }

    public function rules(): void
    {
        $this->validate('draw', ['positive']);
        $this->validate('start', ['numeric']);
        $this->validate('length', ['positive']);
    }

    /**
     * Validates that all requested columns are explicitly allowed.
     *
     * @param string[] $allowed List of permitted column `data` keys.
     * @throws RequestException If any column is not in the allowed list.
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
     * @param array<string, string> $resetNames Associative array of `data => name`.
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
     * @param callable|null $callback function(DTNetColumn $column, string $value): ?Qb
     */
    final public function overrideFilter(?callable $callback): void
    {
        $this->filterCallback = $callback;
    }

    /**
     * Sets a fallback ORDER BY string used when no sorting is specified.
     *
     * @param string|null $defaultContext Example: "id DESC, created_at ASC"
     */
    final public function overrideOrder(?string $defaultContext = null): void
    {
        $this->defaultOrder = $defaultContext;
    }

    /**
     * Generates a comma-separated list of column names for a SQL SELECT clause.
     */
    final public function selection(): string
    {
        $naming = array_map(
            function (DTNetColumn $item): string {
                if (!empty($item->name) && $item->name !== $item->data) {
                    return "{$item->name} AS {$item->data}";
                }
                return $item->name ?: $item->data;
            },
            $this->columns->items
        );

        return implode(', ', $naming);
    }

    /**
     * Builds the SQL WHERE clause based on global and column-specific filters.
     */
    final public function filter(): Qb
    {
        $andConditions = [];

        $callback = $this->filterCallback ?? function (DTNetColumn $column, string $value): ?Qb {
            $field = $column->name ?: $column->data;
            return Qb::like($field, "%{$value}%");
        };

        foreach ($this->columns->items as $column) {
            $value = trim($column->search->value ?? '');
            if ($value !== '' && $column->searchable) {
                $cond = $callback($column, $value);
                if ($cond !== null) {
                    $andConditions[] = $cond;
                }
            }
        }

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
     */
    final public function order(): string
    {
        $orderClauses = [];

        foreach ($this->order as $orderItem) {
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
