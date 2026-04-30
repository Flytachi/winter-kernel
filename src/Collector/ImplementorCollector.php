<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Collector;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use ReflectionClass;

/**
 * Collects all non-abstract classes that implement a given interface.
 *
 * Usage:
 *   $collector = new ImplementorCollector(DbConfigInterface::class);
 *   Scanner::run($rootDir)->collect($collector)->execute();
 *   $refs = $collector->getResult(); // ReflectionClass[]
 */
final class ImplementorCollector implements CollectorInterface
{
    /** @var ReflectionClass[] */
    private array $found = [];

    public function __construct(private readonly string $interface) {}

    public function collect(string $class, ReflectionClass $ref): void
    {
        if ($ref->implementsInterface($this->interface)) {
            $this->found[] = $ref;
        }
    }

    /** @return ReflectionClass[] */
    public function getResult(): array
    {
        return $this->found;
    }
}
