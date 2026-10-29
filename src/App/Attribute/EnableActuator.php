<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Attribute;

use Flytachi\Winter\Kernel\Http\Health\HealthIndicatorInterface;
use Flytachi\Winter\Kernel\Http\Stereotype\Middleware;

/**
 * Enables the `/actuator/*` diagnostic endpoints — the winter analogue of Spring
 * Boot Actuator. Declared on the {@see \Flytachi\Winter\Kernel\WinterApplication} class.
 *
 * Without it the actuator is off. With it, the endpoints are registered and every
 * discovered {@see \Flytachi\Winter\Kernel\Http\Health\HealthContributor} is merged into
 * `/actuator/health`.
 *
 * Only `health` answers unless more are named: the others draw a map of the application —
 * its routes, versions, host, log files — and an endpoint published by default is one
 * published by accident. Spring Boot exposes only health over HTTP for the same reason.
 *
 * ```
 * #[EnableActuator]                                             // health only
 * #[EnableActuator(expose: ['health', 'info', 'metrics'])]      // the ones named
 * #[EnableActuator(expose: ['*'], middleware: InternalOnly::class)]  // all, behind a guard
 * #[EnableActuator(indicator: MyIndicator::class)]              // replace the whole report
 * final class App extends WinterApplication { ... }
 * ```
 *
 * The guard leaves `health` open: load balancers and orchestrators probe it, and they do
 * not pass a guard. `guardHealth: true` puts it behind the guard too.
 *
 * @link https://winterframe.net/docs/actuator Endpoints, response codes and custom checks
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EnableActuator
{
    /**
     * @param class-string<Middleware>|null $middleware Optional guard middleware, for every
     *   exposed endpoint but `health`.
     * @param class-string<HealthIndicatorInterface>|null $indicator Full report override
     *   (defaults to the built-in indicator).
     * @param list<string> $expose The endpoints that answer — the indicator's method names;
     *   `['*']` for all of them. The rest answer 404.
     * @param bool $guardHealth Whether `health` is behind the guard as well.
     */
    public function __construct(
        public ?string $middleware = null,
        public ?string $indicator = null,
        public array $expose = ['health'],
        public bool $guardHealth = false,
    ) {
    }
}
