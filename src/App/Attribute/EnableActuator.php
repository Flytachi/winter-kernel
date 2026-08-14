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
 * ```
 * #[EnableActuator]                                   // default report, open access
 * #[EnableActuator(middleware: InternalOnly::class)]  // behind a guard middleware
 * #[EnableActuator(indicator: MyIndicator::class)]    // replace the whole report
 * final class App extends WinterApplication { ... }
 * ```
 *
 * @link https://winterframe.net/docs/actuator Endpoints, response codes and custom checks
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EnableActuator
{
    /**
     * @param class-string<Middleware>|null $middleware Optional guard middleware.
     * @param class-string<HealthIndicatorInterface>|null $indicator Full report override
     *   (defaults to the built-in indicator).
     */
    public function __construct(
        public ?string $middleware = null,
        public ?string $indicator = null,
    ) {
    }
}
