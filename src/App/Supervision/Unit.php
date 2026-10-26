<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Supervision;

use Flytachi\Winter\Kernel\App\Component;

/**
 * One component under the {@see Supervisor}: its process and where it stands.
 *
 * @internal Mutable state of the supervisor's loop.
 */
final class Unit
{
    public UnitState $state = UnitState::Waiting;
    public int $pid = 0;
    /** @var resource|null The supervisor's end of the channel to the running process. */
    public mixed $socket = null;

    /** Whether the component has been ready at least once since the application started. */
    public bool $everReady = false;
    /** When the current process was started. */
    public float $startedAt = 0.0;
    /** When the next start is due (back-off), for a waiting unit. */
    public float $startAt = 0.0;
    /** When the current start runs out of time; 0 when there is no limit. */
    public float $readyDeadline = 0.0;
    /** When the supervisor sent SIGTERM for a start that timed out; 0 when it did not. */
    public float $timedOutAt = 0.0;
    /** When an outside program asked to stop is killed if it has not; 0 when not stopping. */
    public float $killAt = 0.0;

    /** The process said it ends because it was asked to stop. */
    public bool $stopRequested = false;
    /** The process said it gave up by its own rules. */
    public bool $gaveUp = false;

    /** The process said a process outside the application runs the component. */
    public bool $held = false;
    /** A restart was asked for: once the process has stopped, it starts again at once. */
    public bool $restartPending = false;
    /** The outside process holding the component, while {@see UnitState::Held}. */
    public int $heldBy = 0;
    /** When the outside holder was last looked at. */
    public float $heldCheckedAt = 0.0;
    /** Whether the current wait was told already — once per wait, not once per try. */
    public bool $heldTold = false;

    /** @var list<float> Restarts within the retry window. */
    public array $restarts = [];

    public function __construct(
        public readonly Component $component,
        public readonly string $name,
    ) {
    }

    /** Clears what belonged to the previous process before a new one starts. */
    public function resetRun(): void
    {
        $this->pid = 0;
        $this->socket = null;
        $this->startedAt = 0.0;
        $this->readyDeadline = 0.0;
        $this->timedOutAt = 0.0;
        $this->killAt = 0.0;
        $this->stopRequested = false;
        $this->gaveUp = false;
        $this->held = false;
    }
}
