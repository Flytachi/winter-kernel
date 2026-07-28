<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Health;

/**
 * Health status of a single {@see HealthContributor}, aggregated into the overall
 * `/actuator/health` status (worst wins: any `down` → down, else any `degraded` →
 * degraded, else `up`).
 */
enum Status: string
{
    case Up = 'up';
    case Degraded = 'degraded';
    case Down = 'down';
}
