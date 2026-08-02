<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Daemon;

use Flytachi\Winter\Kernel\Process\Activity;

/**
 * One worker's line in a {@see DaemonStatus}.
 *
 * The supervisor owns {@see $slot} / {@see $state} / {@see $restarts} / {@see $pid}
 * authoritatively; {@see $activity} and the uptime come from the worker's own
 * heartbeat (best-effort, ~1s behind). Serialises to a stable JSON shape for the
 * CLI and the web layer.
 */
final class WorkerStatus implements \JsonSerializable
{
    public function __construct(
        public int $slot,
        public int $pid,
        public SlotState $state,
        public Activity $activity,
        public int $startedAt,
        public int $restarts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'slot'       => $this->slot,
            'pid'        => $this->pid,
            'state'      => $this->state->value,
            'activity'   => $this->activity->value,
            'started_at' => $this->startedAt,
            'uptime'     => $this->startedAt > 0 ? time() - $this->startedAt : 0,
            'restarts'   => $this->restarts,
        ];
    }
}
