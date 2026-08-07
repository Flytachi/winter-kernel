<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Daemon;

/**
 * How a {@see \Flytachi\Winter\Kernel\Process\Stereotype\Daemon} recovers a worker that died unexpectedly.
 *
 * Groups the three restart knobs into one overridable policy object (paired with
 * {@see ScalingPolicy}). Immutable, and non-final so an application can define a
 * reusable named profile by subclassing.
 *
 * ```
 * protected function restart(): RestartPolicy
 * {
 *     return new RestartPolicy(mode: RestartMode::ALWAYS, backoff: 2.0);
 * }
 * ```
 */
readonly class RestartPolicy
{
    /**
     * @param RestartMode $mode When to restart a dead worker.
     * @param int $maxRestarts Give up after this many restarts across the fleet (0 = unlimited).
     * @param float $backoff Base seconds for exponential back-off between restarts.
     */
    public function __construct(
        public RestartMode $mode = RestartMode::ON_FAILURE,
        public int $maxRestarts = 0,
        public float $backoff = 1.0,
    ) {
    }

    /**
     * The daemon-optimal default: restart on failure, unlimited, 1s base back-off.
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * Whether a worker that just exited should be restarted, per {@see $mode}.
     *
     * @param bool $crashed Whether the worker exited abnormally (non-zero / signal).
     */
    public function shouldRestart(bool $crashed): bool
    {
        return $this->mode->shouldRestart($crashed);
    }
}
