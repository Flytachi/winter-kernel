<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Stereotype;

use Flytachi\Winter\K2\Ppa\Entity\RepositoryViewInterface;
use Flytachi\Winter\K2\Ppa\Repository\RepositoryCore;
use Flytachi\Winter\K2\Ppa\Repository\RepositoryViewTrait;

/**
 * Base class for read-only repository implementations.
 *
 * Extend this class to create a repository that provides SELECT operations
 * (find, findAll, count, exists, etc.) without write access.
 * Define {@see RepositoryCore::$dbConfigClassName}, {@see RepositoryCore::$table},
 * and optionally {@see RepositoryCore::$entityClassName} in the subclass.
 *
 * Example:
 * ```
 * class UserViewRepository extends RepositoryView
 * {
 *     protected string $dbConfigClassName = DbConfig::class;
 *     protected string $entityClassName   = UserEntity::class;
 *     public static string $table         = 'users';
 * }
 * ```
 *
 * @see Repository      For full CRUD + View access
 * @see RepositoryCrud  For write-only access
 */
abstract class RepositoryView extends RepositoryCore implements RepositoryViewInterface
{
    use RepositoryViewTrait;

    final public function __construct()
    {
        parent::__construct();
    }
}
