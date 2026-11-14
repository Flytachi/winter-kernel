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
    /** @var resource|null An outside program's stdout and stderr, when its output is parsed. */
    public mixed $output = null;
    /** What the program wrote after its last full line. */
    public string $outputTail = '';
    /** A line too long was cut: the rest of it, up to its newline, is dropped. */
    public bool $outputSkipping = false;
    /**
     * The entry its output is on, logged once the next one starts or the program is quiet.
     *
     * @var array{
     *     level: string, message: string, context: array<string, mixed>,
     *     pid: int, last: ?string, size: int, cut: int,
     * }|null
     */
    public ?array $entry = null;
    /** When the program last wrote to that entry. */
    public float $entryAt = 0.0;

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

    /** The readiness check of an outside program running now; 0 when none is. */
    public int $probePid = 0;
    /** When that check was started. */
    public float $probeStartedAt = 0.0;
    /** When the readiness check is asked next. */
    public float $probeAt = 0.0;

    /** The alive check of an outside program running now; 0 when none is. */
    public int $alivePid = 0;
    /** When that check was started. */
    public float $aliveStartedAt = 0.0;
    /** When the alive check is asked next. */
    public float $aliveAt = 0.0;
    /** Alive checks failed in a row. */
    public int $aliveFailed = 0;
    /** It stopped answering its alive check: it is being stopped, and its end is a crash. */
    public bool $hung = false;
    /** When a hung program that has not ended on its stop signal gets SIGTERM; 0 when not due. */
    public float $termAt = 0.0;

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

    /** The request waiting to hear which process its start became ({@see ComponentControl::reply()}). */
    public ?string $replyTo = null;

    /** @var list<float> Restarts within the retry window. */
    public array $restarts = [];

    /** Why it is not running, or ran into trouble last — for `call application status`. */
    public string $reason = '';
    /** When it entered its current state (Unix time). */
    public int $since = 0;
    /** The state $since belongs to. */
    public ?UnitState $sinceState = null;

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
        $this->output = null;
        $this->outputTail = '';
        $this->outputSkipping = false;
        $this->entry = null;
        $this->entryAt = 0.0;
        $this->startedAt = 0.0;
        $this->readyDeadline = 0.0;
        $this->timedOutAt = 0.0;
        $this->killAt = 0.0;
        $this->probePid = 0;
        $this->probeStartedAt = 0.0;
        $this->probeAt = 0.0;
        $this->alivePid = 0;
        $this->aliveStartedAt = 0.0;
        $this->aliveAt = 0.0;
        $this->aliveFailed = 0;
        $this->hung = false;
        $this->termAt = 0.0;
        $this->stopRequested = false;
        $this->gaveUp = false;
        $this->held = false;
    }
}
