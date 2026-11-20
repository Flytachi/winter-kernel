<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Sse;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Contracts\StreamingResponse;
use Flytachi\Winter\Kernel\Http\Header;
use Flytachi\Winter\Kernel\Http\Response\Sendable;
use Flytachi\Winter\Kernel\Route\RequestWatchdog;
use Flytachi\Winter\Logger\LoggerFactory;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * A Server-Sent Events stream: what a controller returns to keep a browser's `EventSource`
 * fed — the connection stays open, and every event {@see push()}ed reaches the client as
 * it comes.
 *
 * ```php
 * #[GetMapping('/events/clock')]
 * public function clock(): SseEmitter
 * {
 *     return new SseEmitter(function (SseEmitter $emitter): void {
 *         while ($emitter->isOpen()) {
 *             $emitter->push(new SseEvent(data: date('H:i:s'), event: 'tick'));
 *             \Swoole\Coroutine::sleep(1);
 *         }
 *     });
 * }
 * ```
 *
 * The events come from its producer — a function run beside the stream, which ends it by
 * returning — or from anywhere else in the same worker: another request holding it pushes
 * them. An idle stream gets a heartbeat comment, so proxies keep it. It is not under the
 * request's deadline; a `#[Timeout]` of its route caps it.
 *
 * It ends when the client is gone (noticed at the next write: the heartbeat at the latest),
 * on {@see complete()}, when its producer returns, and when the worker stops. Then each
 * {@see onClose()} callback runs once. A client too slow for its events — `$capacity` of
 * them waiting — is closed rather than left behind: its `EventSource` reconnects with
 * `Last-Event-ID`.
 *
 * @link https://winterframe.net/docs/sse Server-Sent Events
 */
final class SseEmitter implements Sendable, Streaming
{
    /** @var array<int, self> The streams open in this worker, closed when it stops. */
    private static array $open = [];

    /** @var list<SseEvent> Events waiting to be written. */
    private array $pending = [];

    /** @var list<callable(): void> */
    private array $onClose = [];

    private bool $closed = false;

    private ?string $lastEventId = null;

    /** The producer's coroutine while it runs. */
    private ?int $producerCid = null;

    /** The producer ended the stream itself: its clean-up after that is its own. */
    private bool $endedByProducer = false;

    /** Whether send() took it: a stream never sent still runs its onClose. */
    private bool $started = false;

    /** The coroutine writing the stream, while it may wait on a client. */
    private ?int $sendCid = null;

    /** Ended without waiting for its client: the connection is cut, not ended. */
    private bool $aborted = false;

    /** Wakes the stream: an event pushed, or the stream closed. */
    private Channel $wake;

    /**
     * @param \Closure(SseEmitter): void|null $producer Run beside the stream; the stream ends
     *     when it returns. Null — the events are pushed from elsewhere.
     * @param float $heartbeat Seconds of silence before a heartbeat comment.
     * @param int $retry Milliseconds the browser waits before it reconnects.
     * @param int $capacity Events that may wait for a slow client before it is closed.
     * @throws \InvalidArgumentException A heartbeat, retry or capacity out of range.
     */
    public function __construct(
        private readonly ?\Closure $producer = null,
        private readonly float $heartbeat = 25.0,
        private readonly int $retry = 3000,
        private readonly int $capacity = 64,
    ) {
        if ($heartbeat <= 0.0 || $retry < 0 || $capacity < 1) {
            throw new \InvalidArgumentException('heartbeat must be above 0, retry 0 or more, capacity 1 or more.');
        }
        $this->wake = new Channel(1);
        // Made in a request: the browser's Last-Event-ID is known now, for the controller to
        // send what was missed. And a stream that never reaches send() — the controller
        // threw, the deadline answered, a middleware replaced it — still runs its onClose
        // when the request ends: a registry it joined forgets it.
        if (Coroutine::getCid() > 0) {
            $id = Header::get('Last-Event-ID');
            $this->lastEventId = $id !== null && $id !== '' ? $id : null;
            Coroutine::defer(function (): void {
                if (!$this->started) {
                    $this->complete();
                    $this->closed();
                }
            });
        }
    }

    /**
     * Sends an event to the client — without waiting for it to be written.
     *
     * @param SseEvent|string $event A string is the data of a `message`.
     * @return bool False when the stream is closed — or has just been closed, its client too
     *     slow to take `$capacity` events.
     */
    public function push(SseEvent|string $event): bool
    {
        if ($this->closed) {
            return false;
        }
        // The capacity is for a client too slow to read, not for what a controller sends
        // before the stream starts (what a reconnect missed).
        if ($this->started && count($this->pending) >= $this->capacity) {
            LoggerFactory::getLogger(self::class)->warning(
                "An event stream closed: its client left {$this->capacity} events unread."
            );
            $this->abort();
            return false;
        }
        $this->pending[] = is_string($event) ? new SseEvent($event) : $event;
        if (Coroutine::getCid() > 0 && $this->wake->length() === 0) {
            $this->wake->push(true, 0.001);
        }
        return true;
    }

    /** Whether the stream still runs: false once its client is gone or it was ended. */
    public function isOpen(): bool
    {
        return !$this->closed;
    }

    /** Ends the stream: what was pushed before is still written, then the response ends. */
    public function complete(): void
    {
        if ($this->producerCid !== null && Coroutine::getCid() === $this->producerCid) {
            $this->endedByProducer = true;
        }
        $this->closed = true;
        $this->wake->close();
    }

    /** Runs once when the stream ends, however it ends — to forget it, say. */
    public function onClose(callable $callback): void
    {
        $this->onClose[] = $callback;
    }

    /**
     * The id of the last event the browser got before it reconnected (`Last-Event-ID`), to
     * send what it missed — known from the controller on; null on a first connection.
     */
    public function lastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /** Ends every stream open in this worker: when it stops, nothing waits for them. */
    public static function closeAll(): void
    {
        // A worker's exit runs outside any coroutine; a coroutine cancelled from there is not
        // woken from a write that waits on a full socket. From a coroutine of its own, it is.
        if (Coroutine::getCid() < 0 && self::$open !== []) {
            Coroutine::create(static fn() => self::closeAll());
            return;
        }
        foreach (self::$open as $emitter) {
            $emitter->abort();
        }
    }

    public function send(HttpResponse $response, HttpRequest $request): void
    {
        if (!$response instanceof StreamingResponse) {
            throw new \LogicException('A Server-Sent Events stream needs the Swoole HTTP server.');
        }
        $this->started = true;
        $id = $request->getHeader('Last-Event-ID');
        $this->lastEventId ??= $id !== null && $id !== '' ? $id : null;

        $response->status(200);
        $response->header('Content-Type', 'text/event-stream; charset=utf-8');
        $response->header('Cache-Control', 'no-cache');
        // nginx would hold the stream in its buffer otherwise.
        $response->header('X-Accel-Buffering', 'no');
        if (strtoupper($request->getMethod()) === 'HEAD') {
            $this->closed = true;
            $response->end('');
            $this->closed();
            return;
        }

        $key = spl_object_id($this);
        self::$open[$key] = $this;
        $this->sendCid = Coroutine::getCid();
        $connected = true;
        try {
            $connected = $response->write("retry: {$this->retry}\n: connected\n\n");
            if ($connected && $this->producer !== null) {
                $this->startProducer();
            }
            while ($connected) {
                while ($connected && $this->pending !== []) {
                    $connected = $response->write(array_shift($this->pending)->toWire());
                }
                if (!$connected || $this->closed) {
                    break;
                }
                $woken = $this->wake->pop($this->heartbeat);
                if ($woken === false && $this->wake->errCode === SWOOLE_CHANNEL_TIMEOUT) {
                    $connected = $response->write(": ping\n\n");
                }
            }
        } catch (\Throwable $e) {
            // The headers are gone: no second answer, only the log.
            if (!$e instanceof Coroutine\CanceledException) {
                LoggerFactory::getLogger(self::class)->error('An event stream failed: ' . $e->getMessage(), [
                    'exception' => $e::class,
                ]);
            }
        } finally {
            // Nothing cancels the clean-up: not abort(), not the route's deadline.
            $this->sendCid = null;
            RequestWatchdog::release(Coroutine::getCid());
            unset(self::$open[$key]);
            $this->closed = true;
            $this->stopProducer();
            if ($connected && $this->aborted) {
                // What waits in the connection's buffer would hold the worker until the
                // client reads it or the network gives up — minutes for a dead one.
                $response->close();
            } elseif ($connected) {
                $response->end('');
            }
            $this->closed();
        }
    }

    /**
     * Ends the stream now, even while its client stopped reading (a full socket, a dead
     * network): the worker stopping, a client too slow. The connection is cut — the browser
     * reconnects — rather than ended: an end waits for the buffer to reach the client.
     */
    private function abort(): void
    {
        $this->aborted = true;
        $this->complete();
        $cid = $this->sendCid;
        if ($cid !== null && $cid !== Coroutine::getCid() && Coroutine::exists($cid)) {
            Coroutine::cancel($cid, true);
        }
    }

    /** The producer, in a coroutine of its own: its end, or its failure, ends the stream. */
    private function startProducer(): void
    {
        $producer = $this->producer;
        Coroutine::create(function () use ($producer): void {
            // Known before the producer runs: create() returns only once it first waits.
            $this->producerCid = Coroutine::getCid();
            try {
                $producer($this);
            } catch (\Throwable $e) {
                if (!$e instanceof Coroutine\CanceledException) {
                    LoggerFactory::getLogger(self::class)->error(
                        'An event stream\'s producer failed: ' . $e->getMessage(),
                        ['exception' => $e::class],
                    );
                }
            } finally {
                $this->producerCid = null;
                $this->complete();
            }
        });
    }

    /**
     * The producer, interrupted wherever it waits — between its ticks, say: a worker that
     * stops must not wait for it to wake, nor a stream whose client is gone.
     */
    private function stopProducer(): void
    {
        $cid = $this->producerCid;
        if ($this->endedByProducer || $cid === null || $cid === Coroutine::getCid() || !Coroutine::exists($cid)) {
            return;
        }
        Coroutine::cancel($cid, true);
    }

    /** Each onClose callback, once. */
    private function closed(): void
    {
        $callbacks = $this->onClose;
        $this->onClose = [];
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                LoggerFactory::getLogger(self::class)->error('An event stream\'s onClose failed: ' . $e->getMessage());
            }
        }
    }
}
