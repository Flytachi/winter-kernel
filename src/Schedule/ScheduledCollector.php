<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Schedule;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\Kernel\Schedule\Trigger\CronTrigger;
use Flytachi\Winter\Kernel\Schedule\Trigger\FixedDelayTrigger;
use Flytachi\Winter\Kernel\Schedule\Trigger\FixedRateTrigger;
use Flytachi\Winter\Kernel\Schedule\Trigger\Trigger;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Discovers every {@see Scheduled} method across the scanned classes and turns
 * each into a {@see ScheduledTask}.
 *
 * Mirrors the router's `MappingCollector`: it inspects public method attributes.
 * A {@see Scheduled} method must be a public, non-static, zero-argument instance
 * method of an instantiable class (it is resolved from the container and called
 * with no arguments), and must declare exactly one trigger — anything else is a
 * configuration error and fails the scan fast.
 *
 * Usage:
 *   $collector = new ScheduledCollector();
 *   ClassScanner::scan($collector);
 *   $tasks = $collector->getResult(); // ScheduledTask[]
 */
final class ScheduledCollector implements CollectorInterface
{
    /** @var ScheduledTask[] */
    private array $tasks = [];

    public function collect(string $class, ReflectionClass $ref): void
    {
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attrs = $method->getAttributes(Scheduled::class);
            if ($attrs === []) {
                continue;
            }
            $this->assertCallable($ref, $method);
            foreach ($attrs as $attr) {
                /** @var Scheduled $scheduled */
                $scheduled = $attr->newInstance();
                $this->tasks[] = new ScheduledTask(
                    className: $class,
                    methodName: $method->getName(),
                    trigger: $this->trigger($scheduled, $class, $method->getName()),
                    initialDelay: max(0.0, $scheduled->initialDelay),
                );
            }
        }
    }

    /** @return ScheduledTask[] */
    public function getResult(): array
    {
        return $this->tasks;
    }

    /**
     * Rejects a method the scheduler could never invoke: an abstract or
     * non-instantiable class, a static method, or one that needs arguments.
     */
    private function assertCallable(ReflectionClass $ref, ReflectionMethod $method): void
    {
        $where = $ref->getName() . '::' . $method->getName() . '()';
        if ($method->isStatic()) {
            throw new ScheduleConfigException("#[Scheduled] {$where} must be a non-static method.");
        }
        if ($method->getNumberOfRequiredParameters() > 0) {
            throw new ScheduleConfigException("#[Scheduled] {$where} must take no required arguments.");
        }
        if ($ref->isAbstract() || $ref->isInterface() || !$ref->isInstantiable()) {
            throw new ScheduleConfigException(
                "#[Scheduled] {$where} is on a non-instantiable class; it cannot be resolved."
            );
        }
    }

    /**
     * Resolves the single trigger declared by the attribute, rejecting zero or
     * more than one.
     */
    private function trigger(Scheduled $scheduled, string $class, string $method): Trigger
    {
        $modes = [];
        if ($scheduled->fixedDelay !== null) {
            $modes[] = 'fixedDelay';
        }
        if ($scheduled->fixedRate !== null) {
            $modes[] = 'fixedRate';
        }
        if ($scheduled->cron !== null) {
            $modes[] = 'cron';
        }

        $where = $class . '::' . $method . '()';
        if ($modes === []) {
            throw new ScheduleConfigException(
                "#[Scheduled] {$where} sets no trigger; use fixedDelay, fixedRate or cron."
            );
        }
        if (count($modes) > 1) {
            throw new ScheduleConfigException(
                "#[Scheduled] {$where} sets more than one trigger (" . implode(', ', $modes) . '); use exactly one.'
            );
        }

        if ($scheduled->fixedDelay !== null) {
            $this->assertPositive($scheduled->fixedDelay, 'fixedDelay', $where);
            return new FixedDelayTrigger($scheduled->fixedDelay);
        }
        if ($scheduled->fixedRate !== null) {
            $this->assertPositive($scheduled->fixedRate, 'fixedRate', $where);
            return new FixedRateTrigger($scheduled->fixedRate);
        }

        if ($scheduled->initialDelay > 0.0) {
            throw new ScheduleConfigException("#[Scheduled] {$where} initialDelay is not supported with cron.");
        }
        try {
            return new CronTrigger((string) $scheduled->cron);
        } catch (InvalidArgumentException $e) {
            throw new ScheduleConfigException("#[Scheduled] {$where} " . $e->getMessage());
        }
    }

    /**
     * A period must be strictly positive — 0 or negative would busy-loop the task.
     */
    private function assertPositive(float $value, string $name, string $where): void
    {
        if ($value <= 0.0) {
            throw new ScheduleConfigException("#[Scheduled] {$where} {$name} must be greater than 0.");
        }
    }
}
