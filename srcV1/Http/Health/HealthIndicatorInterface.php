<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Health;

interface HealthIndicatorInterface
{
    public function health(array $args = []): array;
    public function info(array $args = []): array;
    public function metrics(array $args = []): array;
    public function env(array $args = []): array;
    public function loggers(array $args = []): array;
    public function mappings(array $args = []): array;
//    public function db(): array;
//    public function cache(): array;
//    public function disk(): array;
}
