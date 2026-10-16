<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\WebSocket\Internal;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Response;
use Swoole\WebSocket\CloseFrame;

/**
 * The write side of one upgraded connection: an outbound queue drained by a single
 * writer coroutine.
 *
 * Everything that goes out on the socket — data, pongs, pings, the close frame — goes
 * through this queue. Swoole refuses two coroutines writing one socket at once and
 * answers it with a fatal error that takes the whole process down, so a broadcast from
 * a background task meeting a reply from onMessage() would be enough to kill every
 * connection. One writer per socket makes that impossible, keeps messages in the order
 * they were sent, and lets a slow client fill its own queue instead of stalling the
 * coroutine that sends to it.
 *
 * @internal
 */
final class Wire
{
    /** Close code when the socket went away without a close frame. */
    public const int ABNORMAL = 1006;

    public bool $open = false;
    public int $closeCode = self::ABNORMAL;
    public string $closeReason = '';

    private ?Channel $outbox = null;
    private bool $closeRequested = false;
    private bool $closeQueued = false;

    public function __construct(
        private readonly Response $response,
        private readonly int $queueSize,
    ) {
    }

    /** Called once, right after the upgrade succeeded: from here on frames can go out. */
    public function start(): void
    {
        $this->open = true;
        $this->outbox = new Channel($this->queueSize);
        Coroutine::create(function (): void {
            $this->writeLoop();
        });
    }

    /** Whether the upgrade happened — before it nothing may be written. */
    public function started(): bool
    {
        return $this->outbox !== null;
    }

    /**
     * Queues a frame. False when the connection is closed or closing, or when the
     * client is not reading and its queue is full — the frame is dropped, never waited on.
     */
    public function enqueue(string $data, int $opcode): bool
    {
        if (!$this->open || $this->closeRequested || $this->outbox === null || $this->outbox->isFull()) {
            return false;
        }
        return $this->outbox->push([$data, $opcode]);
    }

    /**
     * Asks for a close: frames queued before it still go out, then the close frame,
     * then the socket is shut. With a full queue (a client that stopped reading) the
     * close goes right after the frame being written now.
     */
    public function close(int $code, string $reason): void
    {
        if (!$this->open || $this->closeRequested) {
            return;
        }
        $this->closeRequested = true;
        $this->closeCode = $code;
        $this->closeReason = $reason;
        if ($this->outbox !== null && !$this->outbox->isFull()) {
            $this->closeQueued = $this->outbox->push(null);
        }
    }

    /** The reader saw the socket end: no more frames can be written, stop the writer. */
    public function gone(): void
    {
        $this->open = false;
        $this->outbox?->close();
    }

    private function writeLoop(): void
    {
        while (true) {
            if ($this->closeRequested && !$this->closeQueued) {
                $this->sendClose();
                return;
            }
            $item = $this->outbox?->pop();
            if ($item === false || $item === null) {
                if ($item === null) {
                    $this->sendClose();
                }
                $this->open = false;
                return;
            }
            [$data, $opcode] = $item;
            if (!$this->response->push($data, $opcode)) {
                $this->open = false;
                $this->response->close();
                return;
            }
        }
    }

    private function sendClose(): void
    {
        $frame = new CloseFrame();
        // 1005/1006 describe what happened, they may never be sent on the wire.
        $frame->code = in_array($this->closeCode, [1005, self::ABNORMAL], true) ? 1000 : $this->closeCode;
        $frame->reason = $this->closeReason;
        $this->response->push($frame);
        $this->open = false;
        // Closing the socket wakes the reader blocked in recv(), which ends the connection.
        $this->response->close();
    }
}
