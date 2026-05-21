<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

use Flytachi\Winter\Cdo\Connection\CDOStatement;
use Flytachi\Winter\K2\Ppa\Entity\RepositoryInterface;
use Flytachi\Winter\K2\Ppa\Entity\RepositoryViewInterface;
use ValueError;

/**
 * Class Paginator
 *
 * Сервис постраничной разбивки данных ядра Winter-Kernel.
 * Полностью безопасен для Swoole (Stateless).
 *
 * @version 5.0
 * @author Flytachi
 */
final class Paginator
{
    /**
     * Offset-based repository pagination with total row count (COUNT).
     *
     * Issues two queries:
     *  1. `SELECT ... LIMIT $size OFFSET $offset` — current page rows.
     *  2. `SELECT COUNT(*) FROM (...)` — without `ORDER BY / LIMIT / OFFSET / FOR`
     *     (see {@see RepositoryInterface::buildSql()} with `ignoreParts`).
     *
     * Mutates the repository — calls `$repo->limit($size, $offset)`. If the
     * caller plans to reuse `$repo` after pagination, clone it beforehand.
     *
     * Example:
     * ```
     * $result = Paginator::repo(
     *     $repo,
     *     size: 20,
     *     offset: 40,
     *     mapper: fn ($row) => ProductResource::from($row),
     * );
     * ```
     *
     * @template TEntity of object
     * @template TItem
     *
     * @param RepositoryViewInterface<TEntity> $repo Source repository with `WHERE / ORDER BY / ...` already applied.
     * @param int $size Page size. Must be `>= 1`.
     * @param int $offset Offset from the start of the result set. Defaults to `0` (first page).
     * @param (callable(TEntity): TItem)|null $mapper Optional per-item transformer (array_map-style).
     *                                                Applied to the fetched rows before result assembly.
     * @param class-string<TEntity>|null $entityClassName Class for row hydration
     *                                                    (forwarded to {@see RepositoryViewInterface::findAll()}).
     *                                                    When `null`, falls back to the repository's configured entity class.
     * @return ($mapper is null
     *     ? PaginationResult<PaginationMeta, TEntity>
     *     : PaginationResult<PaginationMeta, TItem>
     * )
     * @throws ValueError When `$size < 1`.
     */
    public static function repo(
        RepositoryViewInterface $repo,
        int $size,
        int $offset = 0,
        ?callable $mapper = null,
        ?string $entityClassName = null,
    ): PaginationResult {
        if ($size <= 0) {
            throw new ValueError("Size must be a positive integer (>= 1), got: $size.");
        }

        $repo->limit($size, $offset);
        $data = $repo->findAll($entityClassName);

        return new PaginationResult(
            meta: new PaginationMeta(
                offset: $offset,
                size: $size,
                total: self::calculateTotal($repo),
            ),
            data: $mapper === null ? $data : array_map($mapper, $data),
        );
    }

    /**
     * Offset-based pagination of an in-memory array.
     *
     * Counterpart to {@see self::repo()} for plain arrays — no SQL, no COUNT.
     * The full collection must be available up front; `total` is `count($items)`.
     *
     * Example:
     * ```
     * $page = Paginator::array(
     *     items: $rows,
     *     size: 50,
     *     offset: 100,
     *     mapper: fn ($r) => Row::from($r),
     * );
     * ```
     *
     * @template TIn
     * @template TItem
     *
     * @param array<TIn> $items Full collection to paginate over.
     * @param int $size Page size. Must be `>= 1`.
     * @param int $offset Offset from the start of the array. Defaults to `0`.
     * @param (callable(TIn): TItem)|null $mapper Optional per-item transformer (array_map-style).
     *                                            Applied to the sliced page only, not to the full input.
     * @return ($mapper is null
     *     ? PaginationResult<PaginationMeta, TIn>
     *     : PaginationResult<PaginationMeta, TItem>
     * )
     * @throws ValueError When `$size < 1`.
     */
    public static function array(
        array $items,
        int $size,
        int $offset = 0,
        ?callable $mapper = null,
    ): PaginationResult {
        if ($size <= 0) {
            throw new ValueError("Size must be a positive integer (>= 1), got: $size.");
        }

        $data = array_slice($items, $offset, $size);

        return new PaginationResult(
            meta: new PaginationMeta(offset: $offset, size: $size, total: count($items)),
            data: $mapper === null ? $data : array_map($mapper, $data),
        );
    }

    /**
     * Стратегия 2.0: Двунаправленная Enterprise пагинация по курсорам (Стиль Before/After).
     * Одинаково реактивно работает как на Swoole, так и на стандартном PHP-FPM.
     *
     * @param RepositoryInterface $repo
     * @param int                 $size           Количество запрашиваемых элементов.
     * @param string|null         $cursorAfter    Курсор (after_cursor) для движения вперед (в будущее / вниз по списку).
     * @param string|null         $cursorBefore   Курсор (before_cursor) для движения назад (в прошлое / вверх по списку).
     * @param string|null         $entityClassName Имя модели для гидрации.
     */
    final public static function cursor(
        RepositoryInterface $repo,
        int $size,
        ?string $cursorAfter = null,
        ?string $cursorBefore = null,
        ?string $entityClassName = null
    ): PaginationResult {
        if ($size <= 0) {
            throw new \TypeError("Size must be greater than 0.");
        }

        // Запрашиваем на 1 элемент больше для детекции наличия следующей страницы
        $repo->limit($size + 1);

        $isReversed = false;

        if (!is_null($cursorAfter)) {
            // Движение вперед (например, при ORDER BY id DESC)
            $decoded = json_decode(base64_decode($cursorAfter), true);
            if (isset($decoded['id'])) {
                $repo->where('id', '<', (int) $decoded['id']);
            }
        } elseif (!is_null($cursorBefore)) {
            // Движение назад. Разворачиваем условия, чтобы взять элементы "выше" текущего
            $decoded = json_decode(base64_decode($cursorBefore), true);
            if (isset($decoded['id'])) {
                $repo->where('id', '>', (int) $decoded['id']);
                // $repo->changeOrder('id', 'ASC'); // Если ваш репозиторий поддерживает смену направления
                $isReversed = true;
            }
        }

        $list = $repo->findAll($entityClassName) ?: [];
        $count = count($list);

        if ($isReversed) {
            $list = array_reverse($list);
        }

        $hasNextPage = false;
        $hasPrevPage = false;

        if (!is_null($cursorBefore)) {
            $hasPrevPage = $count > $size;
            $hasNextPage = true;

            if ($hasPrevPage) {
                array_shift($list); // Убираем запасной технический элемент с начала
            }
        } else {
            $hasNextPage = $count > $size;
            $hasPrevPage = !is_null($cursorAfter);

            if ($hasNextPage) {
                array_pop($list); // Убираем запасной технический элемент с конца
            }
        }

        $beforeCursor = null;
        $afterCursor = null;

        if (!empty($list)) {
            $firstItem = $list[0];
            $lastItem  = end($list);

            $firstId = is_object($firstItem) ? $firstItem->id : ($firstItem['id'] ?? null);
            $lastId  = is_object($lastItem)  ? $lastItem->id  : ($lastItem['id'] ?? null);

            if ($firstId) {
                $beforeCursor = base64_encode(json_encode(['id' => $firstId]));
            }
            if ($lastId) {
                $afterCursor = base64_encode(json_encode(['id' => $lastId]));
            }
        }

        return new PaginationResult(
            meta: new PaginationMetaCursor(
                size: $size,
                beforeCursor: $beforeCursor,
                afterCursor: $afterCursor,
                hasNextPage: $hasNextPage,
                hasPrevPage: $hasPrevPage,
            ),
            data: $list
        );
    }

    private static function calculateTotal(RepositoryInterface $repo): int
    {
        $sql = $repo->buildSql(ignoreParts: ['order', 'limit', 'offset', 'for']);
        $countSql = 'SELECT COUNT(*) FROM (' . $sql . ') AS tmp';

        $stmt = new CDOStatement($repo->db()->prepare($countSql));
        if ($repo->getSql('binds')) {
            $method = method_exists($stmt, 'bindTypedValue') ? 'bindTypedValue' : 'bindValue';
            foreach ($repo->getSql('binds') as $bind) {
                $stmt->{$method}($bind->getName(), $bind->getValue());
            }
        }
        $stmt->getStmt()->execute();

        return (int) $stmt->getStmt()->fetchColumn();
    }
}