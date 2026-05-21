<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Stereotype;

use Flytachi\Winter\K2\Ppa\Entity\RepositoryViewInterface;
use Flytachi\Winter\K2\Ppa\Repository\RepositoryCore;
use Flytachi\Winter\K2\Ppa\Repository\RepositoryViewTrait;
use stdClass;

/**
 * Lightweight read-only repository for ad-hoc queries without a fixed table.
 *
 * Unlike the abstract stereotypes, `CteRepo` is a concrete final class that accepts
 * the database config class at construction time. Use it for one-off queries
 * that do not belong to a dedicated repository class.
 *
 * The entity type is fixed to {@see stdClass} (CteRepo has no `$entityClassName`
 * configuration). To hydrate into a specific class, pass it explicitly:
 * ```
 * $repo = new CteRepo(DbConfig::class);
 * $rows = $repo->from('reports r')
 *     ->where(Qb::eq('r.active', true))
 *     ->findAll(ReportRow::class);   // list<ReportRow>
 *
 * $raw = $repo->from('reports r')->findAll();  // list<\stdClass>
 * ```
 *
 * @extends RepositoryCore<stdClass>
 * @implements RepositoryViewInterface<stdClass>
 * @use RepositoryViewTrait<stdClass>
 * @see RepositoryView For abstract repository classes with a fixed table.
 */
final class CteRepo extends RepositoryCore implements RepositoryViewInterface
{
    use RepositoryViewTrait;

    /**
     * @param class-string $dbConfigClassName Database configuration class name
     */
    final public function __construct(string $dbConfigClassName)
    {
        $this->dbConfigClassName = $dbConfigClassName;
        parent::__construct();
    }
}
