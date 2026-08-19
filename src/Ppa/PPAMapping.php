<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa;

use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;
use Flytachi\Winter\Cdo\Config\Common\DbConfigInterface;
use Flytachi\Winter\Kernel\Collector\ImplementorCollector;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Ppa\Declaration;
use Flytachi\Winter\Ppa\Entity\RepositoryInterface;
use Flytachi\Winter\Ppa\PPAMapping as Mapping;

/**
 * Finds the project's database classes and hands them to the PPA package.
 *
 * The mapping itself — reflection to {@see Declaration} — lives in
 * {@see \Flytachi\Winter\Ppa\PPAMapping}, which knows nothing about projects. What is
 * left here is the half that does: where the source tree is, and how to walk it. That
 * split is why the package can be used without the framework, and why the framework can
 * keep discovering classes the way it does everywhere else.
 *
 * The signatures are unchanged from when both halves lived here, so console commands and
 * applications calling this need no edit.
 */
final class PPAMapping
{
    /**
     * Every database config declared in the project, instantiated.
     *
     * @return DbConfigInterface[]
     */
    public static function scanningConfigs(?string $rootDir = null): array
    {
        return Mapping::configsFrom(self::scan(DbConfigInterface::class, $rootDir));
    }

    /**
     * The declaration built from every repository in the project — the tables it expects
     * a database to have.
     */
    public static function scanningDeclaration(?string $rootDir = null): Declaration
    {
        return Mapping::declarationFrom(self::scan(RepositoryInterface::class, $rootDir));
    }

    /**
     * @param class-string $contract
     * @return array<\ReflectionClass>
     */
    private static function scan(string $contract, ?string $rootDir): array
    {
        // Applications call this directly too, and the return type alone is enough to
        // fatal without the package. Better to say which package is missing.
        DepSupport::demand(Dep::Ppa, 'Scanning database classes');

        $collector = new ImplementorCollector($contract);
        ClassScanner::scanner($rootDir ?? Kernel::$pathRoot)->collect($collector)->execute();

        return $collector->getResult();
    }
}
