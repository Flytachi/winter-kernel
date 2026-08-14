<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Health;

/**
 * The full `/actuator/*` report. One method per endpoint: the route
 * `/actuator/{method}` calls the method of that name, so `mappings()` answers
 * `/actuator/mappings`.
 *
 * Implement this to replace the report entirely — every method is then yours to
 * provide. To change one endpoint and keep the rest, extend {@see HealthIndicator}
 * instead. Wire it up with
 * {@see \Flytachi\Winter\Kernel\App\Attribute\EnableActuator}'s `indicator`
 * argument.
 *
 * @link https://winterframe.net/docs/actuator Replacing the report
 */
interface HealthIndicatorInterface
{
    public function health(): array;
    public function pools(): array;
    public function info(): array;
    public function metrics(): array;
    public function env(): array;
    public function loggers(): array;
    public function mappings(): array;
}
