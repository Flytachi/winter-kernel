<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\Pagination;

use Flytachi\Winter\Cdo\Connection\CDOStatement;
use Flytachi\Winter\K2\Ppa\Entity\RepositoryInterface;
use TypeError;

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
     * Стратегия 1.1: Классическая пагинация через Репозиторий БД (с COUNT)
     */
    final public static function repo(
        RepositoryInterface $repo,
        ?int $size = null,
        int $page = 1,
        ?string $modelClassName = null
    ): PaginationResult {
        $page = max($page, 1);

        if (!is_null($size)) {
            $repo->limit($size, $size * ($page - 1));
        } elseif (!$repo->getSql('limit')) {
            throw new TypeError("Missing 'size/limit' value in repository or arguments.");
        }

        $currentSize = (int) $repo->getSql('limit');
        $totalItem   = self::calculateTotal($repo);

        return new PaginationResult(
            meta: new PaginationMeta(page: $page, size: $currentSize, total: $totalItem),
            data: $repo->findAll($modelClassName) ?: []
        );
    }

    final public static function array(
        array $items,
        int $size,
        int $page = 1
    ): PaginationResult {
        if ($size <= 0) {
            throw new TypeError("Size must be a positive integer greater than 0.");
        }

        $page = max($page, 1);
        $totalItem = count($items);
        $offset = $size * ($page - 1);

        return new PaginationResult(
            meta: new PaginationMeta(page: $page, size: $size, total: $totalItem),
            data: array_splice($items, $offset, $size)
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
     * @param string|null         $modelClassName Имя модели для гидрации.
     */
    final public static function cursor(
        RepositoryInterface $repo,
        int $size,
        ?string $cursorAfter = null,
        ?string $cursorBefore = null,
        ?string $modelClassName = null
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

        $list = $repo->findAll($modelClassName) ?: [];
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
        $sql = $repo->buildSql();
        $sql = preg_replace('/\s+LIMIT\s+\d+/i', '', $sql);
        $sql = preg_replace('/\s+OFFSET\s+\d+/i', '', $sql);
        $sql = preg_replace('/\s+FOR\s+UPDATE/i', '', $sql);
        $sql = preg_replace('/\s+ORDER\s+BY\s+.+$/i', '', $sql);

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