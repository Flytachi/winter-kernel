<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Health;

/**
 * A single health check contributed to `/actuator/health` — the winter analogue of
 * Spring's `HealthContributor`. Any class implementing it is discovered on the boot
 * scan (like {@see \Flytachi\Winter\Kernel\App\Config\WebConfigurer}) and, when the
 * actuator is enabled via {@see \Flytachi\Winter\Kernel\App\Attribute\EnableActuator},
 * merged into the aggregated report under {@see name()}.
 *
 * Contributors are resolved from the container (constructor autowiring works) and
 * {@see check()} runs on every `/actuator/health` request, so the status is live.
 *
 * ```
 * final class DatabaseHealth implements HealthContributor
 * {
 *     public function __construct(private Db $db) {}
 *
 *     public function name(): string { return 'db'; }
 *
 *     public function check(): HealthStatus
 *     {
 *         return $this->db->ping()
 *             ? HealthStatus::up()->withDetail('latency_ms', $this->db->latency())
 *             : HealthStatus::down()->withDetail('reason', 'connection failed');
 *     }
 * }
 * ```
 *
 * @link https://winterframe.net/docs/actuator Writing your own checks
 */
interface HealthContributor
{
    /** The component key this check appears under in the report (e.g. 'db'). */
    public function name(): string;

    /** Runs the check; called live on every `/actuator/health` request. */
    public function check(): HealthStatus;
}
