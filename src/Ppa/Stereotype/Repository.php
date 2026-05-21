<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Ppa\Stereotype;

use Flytachi\Winter\K2\Ppa\Entity\RepositoryCrudInterface;
use Flytachi\Winter\K2\Ppa\Entity\RepositoryViewInterface;
use Flytachi\Winter\K2\Ppa\Repository\RepositoryCore;
use Flytachi\Winter\K2\Ppa\Repository\RepositoryCrudTrait;
use Flytachi\Winter\K2\Ppa\Repository\RepositoryViewTrait;

/**
 * Base class for full-access repository implementations (CRUD and View).
 *
 * Combines all read and write operations in a single class. Extend this
 * for the typical repository that needs both SELECT and write access.
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
 * @implements RepositoryViewInterface<TEntity>
 * @use RepositoryViewTrait<TEntity>
 * @see RepositoryView For read-only access.
 * @see RepositoryCrud For write-only access.
 */
abstract class Repository extends RepositoryCore implements RepositoryCrudInterface, RepositoryViewInterface
{
    use RepositoryCrudTrait;
    use RepositoryViewTrait;

    final public function __construct()
    {
        parent::__construct();
    }
}
