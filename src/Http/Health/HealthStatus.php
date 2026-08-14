<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Health;

/**
 * The result a {@see HealthContributor} returns — a {@see Status} plus optional
 * detail fields. Built with the named factories, never the constructor:
 *
 * ```
 * return $this->db->ping()
 *     ? HealthStatus::up()->withDetail('latency_ms', $this->db->latency())
 *     : HealthStatus::down()->withDetail('reason', 'connection failed');
 * ```
 *
 * @link https://winterframe.net/docs/actuator Choosing a status and adding details
 */
final class HealthStatus
{
    /** @var array<string, mixed> */
    private array $details = [];

    private function __construct(private readonly Status $status)
    {
    }

    public static function up(): self
    {
        return new self(Status::Up);
    }

    public static function degraded(): self
    {
        return new self(Status::Degraded);
    }

    public static function down(): self
    {
        return new self(Status::Down);
    }

    public function withDetail(string $key, mixed $value): self
    {
        $this->details[$key] = $value;
        return $this;
    }

    public function status(): Status
    {
        return $this->status;
    }

    /**
     * The wire shape merged into the actuator report.
     *
     * @return array{status: string, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['status' => $this->status->value, 'details' => $this->details];
    }
}
