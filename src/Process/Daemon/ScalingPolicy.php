<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Daemon;

/**
 * How a {@see Daemon} smooths fleet-size changes — stability over speed.
 *
 * {@see Daemon::desiredReplicas()} is a signal, not a command: the supervisor
 * damps it so a noisy or naive value never thrashes the fleet. The model is
 * asymmetric (like Kubernetes HPA): scale up quickly, scale down only when low
 * demand is sustained.
 *
 * Immutable, and non-final so an application can define reusable named profiles
 * by subclassing. The defaults are tuned for a daemon — most never touch them.
 *
 * The three timing knobs answer different questions:
 * - {@see $scaleDownStabilization} — *whether* to shrink (is low demand sustained);
 * - {@see $scaleStep} — *how many* workers to change per action;
 * - {@see $cooldown} — *how often* an action may happen.
 */
readonly class ScalingPolicy
{
    /**
     * @param float $scaleInterval How often (seconds) the supervisor polls desiredReplicas() / tick().
     * @param float $scaleUpDelay Demand must hold for this long (seconds) before scaling up; 0 = at once.
     * @param float $scaleDownStabilization Low demand must hold for this long (seconds) before shrinking.
     * @param float $cooldown Minimum seconds between two scaling actions.
     * @param int $scaleStep Maximum workers added/removed per action; 0 = unlimited.
     */
    public function __construct(
        public float $scaleInterval = 1.0,
        public float $scaleUpDelay = 0.0,
        public float $scaleDownStabilization = 60.0,
        public float $cooldown = 3.0,
        public int $scaleStep = 0,
    ) {
    }

    /**
     * The daemon-optimal default: stability over speed.
     */
    public static function default(): self
    {
        return new self();
    }
}
