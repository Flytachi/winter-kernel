<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Collector;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use ReflectionClass;

/**
 * Collects all non-abstract classes that extend any of the given parent classes.
 *
 * Usage:
 *   $collector = new SubclassCollector(Cmd::class, CmdCustom::class);
 *   Scanner::run($rootDir)->collect($collector)->execute();
 *   $refs = $collector->getResult(); // ReflectionClass[]
 */
final class SubclassCollector implements CollectorInterface
{
    /** @var class-string[] */
    private array $parents;

    /** @var ReflectionClass[] */
    private array $found = [];

    public function __construct(string ...$parents)
    {
        $this->parents = $parents;
    }

    public function collect(string $class, ReflectionClass $ref): void
    {
        foreach ($this->parents as $parent) {
            if ($ref->isSubclassOf($parent)) {
                $this->found[] = $ref;
                return;
            }
        }
    }

    /** @return ReflectionClass[] */
    public function getResult(): array
    {
        return $this->found;
    }
}
