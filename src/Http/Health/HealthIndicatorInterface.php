<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Health;

interface HealthIndicatorInterface
{
    public function health(): array;
    public function info(): array;
    public function metrics(): array;
    public function env(): array;
    public function loggers(): array;
    public function mappings(): array;
}
