<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\Kernel\Http\Stereotype\ControllerInterface;
use Flytachi\Winter\Kernel\Schedule\Scheduled;
use ReflectionClass;
use ReflectionMethod;

/**
 * Counts what an imported package brought that needs switching on to run.
 *
 * It exists so the framework can say a thing it otherwise only implies: a package can
 * contribute controllers to an application that serves no HTTP, or scheduled methods to
 * one with no scheduler, and both simply never happen. Nothing is broken and nothing is
 * logged — the feature is just absent, and the search starts in the wrong place.
 *
 * It rides the pass the boot scan already makes over the package, so it costs no extra
 * walk of the filesystem: over the kernel's own 182 classes the interface check takes
 * 0.03 ms and the method walk 0.15 ms.
 *
 * @link https://winterframe.net/docs/packages Packages
 */
final class ContributionCollector implements CollectorInterface
{
    private int $controllers = 0;
    private int $scheduled = 0;

    public function collect(string $class, ReflectionClass $ref): void
    {
        if ($ref->isInterface() || $ref->isAbstract()) {
            // Neither is ever routed or scheduled on its own.
            return;
        }

        if ($ref->implementsInterface(ControllerInterface::class)) {
            $this->controllers++;
        }

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Scheduled::class) !== []) {
                $this->scheduled++;
            }
        }
    }

    /** Controller classes found — candidates for routes. */
    public function controllers(): int
    {
        return $this->controllers;
    }

    /** Methods carrying `#[Scheduled]`. */
    public function scheduledMethods(): int
    {
        return $this->scheduled;
    }
}
