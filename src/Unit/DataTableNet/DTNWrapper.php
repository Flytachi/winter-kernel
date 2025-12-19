<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\DataTableNet;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Edo\Entity\RepositoryInterface;

class DTNWrapper
{
    /**
     * Builds a paginated response compatible with DataTables from the given repository and request.
     *
     * This method applies selection, filtering, ordering, and pagination based on the DataTables request.
     * It can optionally perform separate counting for filtered and unfiltered records.
     *
     * @param RepositoryInterface $repo Repository instance used to fetch data.
     * @param DataTableNetRequest $request The DataTables-compatible request object
     * containing pagination, search, and sort configuration.
     * @param Qb|null $headQueryBuilder Optional base query conditions applied before filters (e.g. for scoping).
     * @param bool $accurateCounts If true, performs separate counting for total and
     * filtered records. If false, total = filtered.
     *
     * @return DataTableNetResponse The formatted response for DataTables.
     *
     * @throws DataTableNetException|\Throwable If the repository already contains
     * query modifications that conflict with the paginator logic.
     */
    final public static function paginator(
        RepositoryInterface $repo,
        DataTableNetRequest $request,
        ?Qb $headQueryBuilder = null,
        bool $accurateCounts = true
    ): DataTableNetResponse {
        if ($repo->getSql('option') !== null) {
            throw new DataTableNetException(
                'Repository already has a SELECT clause defined. ' .
                'The paginator requires the SELECT clause to be empty.'
            );
        }
        if ($repo->getSql('where') !== null) {
            throw new DataTableNetException(
                'Repository already has a WHERE clause defined. ' .
                'The paginator builds its own filter and expects no predefined WHERE conditions.'
            );
        }
        if ($repo->getSql('order') !== null) {
            throw new DataTableNetException(
                'Repository already has an ORDER BY clause defined. ' .
                'The paginator applies its own ordering logic and requires ORDER BY to be unset.'
            );
        }

        try {
            // Setup base repo
            $repo->select($request->selection());
            $repo->where($headQueryBuilder);
            $repo->orderBy($request->order());
            $repo->limit($request->length, $request->start);

            // 1. Total count
            if ($accurateCounts) {
                $recordsTotal = self::countRecords($repo);
            }

            // 2. Filtered count
            $repo->cleanCache('where');
            $repo->cleanCache('binds');
            $repo->where(Qb::and(
                $headQueryBuilder ?: Qb::empty(),
                $request->filter()
            ));

            $recordsFiltered = self::countRecords($repo);

            return new DataTableNetResponse(
                $request->draw,
                $recordsTotal ?? $recordsFiltered,
                $recordsFiltered,
                $repo->findAll()
            );
        } catch (\Throwable $throwable) {
            if ((int) $throwable->getCode() === 42703) {
                $message = $throwable->getMessage();

                if (preg_match('/column "(.*?)" does not exist/i', $message, $matches)) {
                    $invalidColumn = $matches[1];
                    $userMessage = "Invalid column reference: `{$invalidColumn}` does not exist in the table";
                } else {
                    $userMessage = "Invalid column name used in request. Please check your field mappings";
                }

                throw new DataTableNetException(
                    $userMessage,
                    previous: $throwable
                );
            }
            throw $throwable;
        }
    }

    /**
     * Prepares and executes a COUNT query based on the repository's current SQL.
     *
     * @param RepositoryInterface $repo Repository instance with prepared query state.
     * @return int The number of rows matching the query.
     */
    private static function countRecords(RepositoryInterface $repo): int
    {
        $sql = self::prepareCountSql($repo->buildSql());
        $stmt = $repo->db()->prepare($sql);

        if ($repo->getSql('binds')) {
            foreach ($repo->getSql('binds') as $hash => $value) {
                $stmt->bindValue($hash, $value);
            }
        }

        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Transforms a SELECT query into a COUNT query by stripping pagination and wrapping in a subquery.
     *
     * @param string $sql Raw SQL query string.
     * @return string SQL query wrapped in COUNT(*).
     */
    private static function prepareCountSql(string $sql): string
    {
        $sql = preg_replace('/\s+LIMIT\s+\d+/i', '', $sql);
        $sql = preg_replace('/\s+OFFSET\s+\d+/i', '', $sql);
        $sql = preg_replace('/\s+FOR\s+UPDATE/i', '', $sql);
        return 'SELECT COUNT(*) FROM (' . $sql . ') AS tmp';
    }
}
