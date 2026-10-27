<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Supervision;

/**
 * One request to the supervisor, as {@see ComponentControl::pending()} hands it over.
 *
 * @internal Between the framework's control surface and the supervisor.
 */
final readonly class ControlRequest
{
    /**
     * @param class-string $class The component's class.
     * @param string $action {@see ComponentControl::START} or {@see ComponentControl::RESTART}.
     * @param array<string, string> $args What it is started with, when the application takes it in.
     */
    public function __construct(
        public string $class,
        public string $action,
        public array $args = [],
    ) {
    }
}
