<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit;

use Flytachi\Winter\Cdo\Connection\CDOStatement;
use Flytachi\Winter\K2\Ppa\Entity\RepositoryInterface;
use TypeError;

/**
 * Class Wrapper
 *
 * The `Wrapper` class provides a set of utility functions associated with pagination.
 *
 * The methods provided by `Wrapper` include:
 *
 * - `paginator(Repository $repo, ?int $limit = null, int $page = 1, ?string $modelClassName = null): array`:
 * Generates the pagination links for a repository results.
 * - `paginatorDecoration(Repository $repo, ?int $limit = null, int $page = 1, ?string $modelClassName = null): array`:
 * Creates a decoration for the paginator.
 * - `panel(Repository $repo): string`: Builds the pagination panel.
 * - `urlToArray(string $url): array`: Converts the URL query string to an associative array.
 * - `arrayToUrl(array $get): string`: Converts an associative array of parameters to a URL query string.
 *
 * @version 4.0
 * @author Flytachi
 */
final class Wrapper
{
    private static int $totalPages;
    private static int $totalItem;
    private static int $currentPage;
    private static int $limitPage;

    /**
     * Paginate the results of a repository query.
     *
     * @param array|RepositoryInterface $repo The repository to paginate the results from.
     * @param int|null $limit The maximum number of items per page. If null,
     * the repository's default limit will be used.
     * @param int $page The current page number.
     * @param string|null $modelClassName The name of the model.
     *
     * @return array The paginated results as an associative array, with the following keys:
     * - pagination: An array containing information about the pagination, including:
     *   - current: The current page number.
     *   - previous: The previous page number. If there is no previous page, this will be 0.
     *   - next: The next page number. If there is no next page, this will be 0.
     *   - perPage: The maximum number of items per page.
     *   - totalItem: The total number of items.
     *   - totalPage: The total number of pages.
     * - list: An array of items fetched from the repository using the specified method.
     *
     * @throws TypeError If the limit is not set and the repository does not have a default limit.
     */
    final public static function paginator(
        array|RepositoryInterface $repo,
        ?int $limit = null,
        int $page = 1,
        ?string $modelClassName = null
    ): array {
        if ($repo instanceof RepositoryInterface) {
            if (!is_null($limit)) {
                $repo->limit($limit, $limit * ($page - 1));
            } else {
                if (!$repo->getSql('limit')) {
                    throw new TypeError("Not value 'Limit'!");
                }
            }
            self::init($repo);
            return [
                'pagination' => [
                    'current'   => self::$currentPage,
                    'previous'  => self::$currentPage - 1,
                    'next'      => (self::$totalPages > self::$currentPage) ? self::$currentPage + 1 : 0,
                    'perPage'   => self::$limitPage,
                    'totalItem' => self::$totalItem,
                    'totalPage' => self::$totalPages,
                ],
                'list' => $repo->findAll($modelClassName) ?: [],
            ];
        } else {
            if (is_null($limit)) {
                throw new TypeError("Not value 'Limit'!");
            }
            $totalItem = count($repo);
            $totalPage = ceil($totalItem / $limit);
            $offset    = $limit * ($page - 1);

            return [
                'pagination' => [
                    'current'   => $page,
                    'previous'  => $page - 1,
                    'next'      => ($totalPage > $page) ? $page + 1 : 0,
                    'perPage'   => $limit,
                    'totalItem' => $totalItem,
                    'totalPage' => ceil($totalItem / $limit),
                ],
                'list' => array_splice($repo, $offset, $limit),
            ];
        }
    }

    private static function init(RepositoryInterface $repo): void
    {
        $sql = $repo->buildSql();
        $sql = preg_replace('/\s+LIMIT\s+\d+/i', '', $sql);
        $sql = preg_replace('/\s+OFFSET\s+\d+/i', '', $sql);
        $sql = preg_replace('/\s+FOR\s+UPDATE/i', '', $sql);

        $countSql = 'SELECT COUNT(*) FROM (' . $sql . ') AS tmp';

        self::$limitPage   = (int) $repo->getSql('limit');
        self::$currentPage = (self::$limitPage + $repo->getSql('offset')) / self::$limitPage;

        $stmt = new CDOStatement($repo->db()->prepare($countSql));
        if ($repo->getSql('binds')) {
            $method = method_exists($stmt, 'bindTypedValue') ? 'bindTypedValue' : 'bindValue';
            foreach ($repo->getSql('binds') as $bind) {
                $stmt->{$method}($bind->getName(), $bind->getValue());
            }
        }

        $stmt->getStmt()->execute();
        self::$totalItem  = (int) $stmt->getStmt()->fetchColumn();
        self::$totalPages = (int) ceil(self::$totalItem / self::$limitPage);
    }
}
