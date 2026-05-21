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
 * Subclasses bind `TEntity` by adding an `@extends` PHPDoc tag on the class
 * declaration that pins the template parameter to their concrete entity class.
 * Without the binding, finder return values (`find`, `findAll`, `findById`,
 * ...) fall back to `object`.
 *
 * Required subclass properties: {@see RepositoryCore::$dbConfigClassName},
 * {@see RepositoryCore::$entityClassName}, {@see RepositoryCore::$table}.
 *
 * @template TEntity of object
 * @extends RepositoryCore<TEntity>
 * @implements RepositoryViewInterface<TEntity>
 * @use RepositoryViewTrait<TEntity>
 * @see Repository For full CRUD + View access.
 * @see RepositoryCrud For write-only access.
 */
abstract class RepositoryView extends RepositoryCore implements RepositoryViewInterface
{
    use RepositoryViewTrait;

    final public function __construct()
    {
        parent::__construct();
    }
}
