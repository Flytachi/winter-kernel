<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Stereotype;

use Flytachi\Winter\K2\Ppa\Entity\RepositoryCrudInterface;
use Flytachi\Winter\K2\Ppa\Repository\RepositoryCore;
use Flytachi\Winter\K2\Ppa\Repository\RepositoryCrudTrait;

/**
 * Base class for write-only repository implementations.
 *
 * Extend this class to create a repository with INSERT, UPDATE, DELETE,
 * and UPSERT operations but no SELECT helpers. Define
 * {@see RepositoryCore::$dbConfigClassName} and {@see RepositoryCore::$table}
 * in the subclass.
 *
 * Example:
 * ```
 * class UserWriteRepository extends RepositoryCrud
 * {
 *     protected string $dbConfigClassName = DbConfig::class;
 *     public static string $table         = 'users';
 * }
 * ```
 *
 * @see Repository      For full CRUD + View access
 * @see RepositoryView  For read-only access
 */
abstract class RepositoryCrud extends RepositoryCore implements RepositoryCrudInterface
{
    use RepositoryCrudTrait;

    final public function __construct()
    {
        parent::__construct();
    }
}
