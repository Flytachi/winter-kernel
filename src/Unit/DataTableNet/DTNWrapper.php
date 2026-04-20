<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Unit\DataTableNet;

use Flytachi\Winter\Cdo\Connection\CDOStatement;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\K2\Ppa\Entity\RepositoryInterface;

class DTNWrapper
{
    /**
     * Builds a paginated response compatible with DataTables from the given repository and request.
     *
     * @param RepositoryInterface $repo Repository instance used to fetch data.
     * @param DataTableNetRequest $request The DataTables-compatible request object.
     * @param Qb|null $headQueryBuilder Optional base query conditions applied before filters.
     * @param bool $accurateCounts If true, performs separate counting for total and filtered records.
     *
     * @return DataTableNetResponse
     * @throws DataTableNetException|\Throwable
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
            $repo->select($request->selection());
            $repo->where($headQueryBuilder);
            $repo->orderBy($request->order());
            $repo->limit($request->length, $request->start);

            if ($accurateCounts) {
                $recordsTotal = self::countRecords($repo);
            }

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

                throw new DataTableNetException($userMessage, previous: $throwable);
            }
            throw $throwable;
        }
    }

    private static function countRecords(RepositoryInterface $repo): int
    {
        $sql  = self::prepareCountSql($repo->buildSql());
        $stmt = new CDOStatement($repo->db()->prepare($sql));

        if ($repo->getSql('binds')) {
            $method = method_exists($stmt, 'bindTypedValue') ? 'bindTypedValue' : 'bindValue';
            foreach ($repo->getSql('binds') as $bind) {
                $stmt->{$method}($bind->getName(), $bind->getValue());
            }
        }

        $stmt->getStmt()->execute();
        return (int) $stmt->getStmt()->fetchColumn();
    }

    private static function prepareCountSql(string $sql): string
    {
        $sql = preg_replace('/\s+LIMIT\s+\d+/i', '', $sql);
        $sql = preg_replace('/\s+OFFSET\s+\d+/i', '', $sql);
        $sql = preg_replace('/\s+FOR\s+UPDATE/i', '', $sql);
        return 'SELECT COUNT(*) FROM (' . $sql . ') AS tmp';
    }
}
