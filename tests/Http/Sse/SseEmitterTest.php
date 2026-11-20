<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Sse;

use Flytachi\Winter\Kernel\Http\Header;
use Flytachi\Winter\Kernel\Http\Sse\SseEmitter;
use Flytachi\Winter\Kernel\Http\Sse\SseEvent;
use Flytachi\Winter\Kernel\Http\Sse\Streaming;
use Flytachi\Winter\Kernel\Tests\Http\Sse\Fixtures\FakeStreamingResponse;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeRequest;
use Flytachi\Winter\Kernel\Route\RequestWatchdog;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * A stream as the router sends it: the headers, `retry`, the events pushed from elsewhere,
 * the heartbeat, and how it ends — the client gone, `complete()`, the worker stopping.
 * In processes of their own: a coroutine run in PHPUnit's process breaks later fork tests.
 */
#[RunTestsInSeparateProcesses]
#[RequiresPhpExtension('swoole')]
final class SseEmitterTest extends TestCase
{
    /** Runs the stream in a coroutine and $meanwhile in another, both inside one scheduler. */
    private static function stream(SseEmitter $emitter, FakeStreamingResponse $response, ?\Closure $meanwhile = null, array $headers = [], string $method = 'GET'): void
    {
        Coroutine\run(static function () use ($emitter, $response, $meanwhile, $headers, $method): void {
            if ($meanwhile !== null) {
                Coroutine::create($meanwhile);
            }
            $emitter->send($response, new FakeRequest($method, '/events', $headers));
        });
    }

    public function test_the_headers_and_the_first_lines(): void
    {
        $emitter = new SseEmitter(retry: 2500);
        $response = new FakeStreamingResponse();
        self::stream($emitter, $response, static fn() => $emitter->complete());

        self::assertInstanceOf(Streaming::class, $emitter);
        self::assertSame(200, $response->status);
        self::assertSame('text/event-stream; charset=utf-8', $response->headers['content-type'] ?? null);
        self::assertSame('no-cache', $response->headers['cache-control'] ?? null);
        self::assertSame('no', $response->headers['x-accel-buffering'] ?? null);
        self::assertStringStartsWith("retry: 2500\n: connected\n\n", $response->wire());
        self::assertTrue($response->ended, 'completed: the response is ended');
    }

    public function test_events_pushed_from_elsewhere_reach_the_client(): void
    {
        $emitter = new SseEmitter();
        $response = new FakeStreamingResponse();
        self::stream($emitter, $response, static function () use ($emitter): void {
            Coroutine::sleep(0.05);
            $emitter->push(new SseEvent('one', event: 'message', id: '1'));
            $emitter->push('two');
            Coroutine::sleep(0.05);
            $emitter->complete();
        });

        self::assertStringContainsString("event: message\nid: 1\ndata: one\n\n", $response->wire());
        self::assertStringContainsString("data: two\n\n", $response->wire(), 'a string is the data of a message');
    }

    public function test_events_pushed_before_the_stream_starts_are_kept(): void
    {
        $emitter = new SseEmitter();
        $emitter->push('early');
        $response = new FakeStreamingResponse();
        self::stream($emitter, $response, static function () use ($emitter): void {
            Coroutine::sleep(0.05);
            $emitter->complete();
        });

        self::assertStringContainsString("data: early\n\n", $response->wire());
    }

    public function test_an_idle_stream_sends_a_heartbeat(): void
    {
        $emitter = new SseEmitter(heartbeat: 0.05);
        $response = new FakeStreamingResponse();
        self::stream($emitter, $response, static function () use ($emitter): void {
            Coroutine::sleep(0.18);
            $emitter->complete();
        });

        self::assertGreaterThanOrEqual(2, substr_count($response->wire(), ": ping\n\n"), $response->wire());
    }

    public function test_a_client_gone_ends_the_stream_and_runs_on_close_once(): void
    {
        $closed = 0;
        $emitter = new SseEmitter(heartbeat: 0.02);
        $emitter->onClose(static function () use (&$closed): void {
            $closed++;
        });
        $response = new FakeStreamingResponse(goneAfter: 2);
        self::stream($emitter, $response);

        self::assertSame(1, $closed);
        self::assertFalse($emitter->isOpen());
        self::assertFalse($response->ended, 'nobody to end it for');
        self::assertFalse($emitter->push('late'), 'a closed stream takes nothing');
    }

    public function test_a_producer_runs_beside_the_stream_and_its_end_ends_it(): void
    {
        $emitter = new SseEmitter(static function (SseEmitter $emitter): void {
            foreach (['a', 'b', 'c'] as $tick) {
                $emitter->push(new SseEvent($tick, event: 'tick'));
                Coroutine::sleep(0.01);
            }
        });
        $response = new FakeStreamingResponse();
        self::stream($emitter, $response);

        self::assertSame(3, substr_count($response->wire(), "event: tick\n"));
        self::assertTrue($response->ended);
    }

    public function test_a_producer_that_throws_closes_the_stream(): void
    {
        $emitter = new SseEmitter(static function (SseEmitter $emitter): void {
            $emitter->push('before');
            throw new \RuntimeException('the producer broke');
        });
        $response = new FakeStreamingResponse();
        self::stream($emitter, $response);

        self::assertFalse($emitter->isOpen());
        self::assertTrue($response->ended, 'closed, not answered again');
        self::assertNull($response->headers['content-type:'] ?? null);
    }

    public function test_close_all_ends_every_open_stream(): void
    {
        $emitters = [new SseEmitter(), new SseEmitter()];
        $responses = [new FakeStreamingResponse(), new FakeStreamingResponse()];
        Coroutine\run(static function () use ($emitters, $responses): void {
            foreach ($emitters as $i => $emitter) {
                Coroutine::create(static fn() => $emitter->send($responses[$i], new FakeRequest('GET', '/events')));
            }
            Coroutine::sleep(0.05);
            SseEmitter::closeAll();
        });

        foreach ($emitters as $i => $emitter) {
            self::assertFalse($emitter->isOpen());
            self::assertTrue($responses[$i]->cut, 'the worker stops: the connection is cut, the browser reconnects');
        }
    }

    public function test_a_sleeping_producer_does_not_hold_the_stop(): void
    {
        // A producer between its ticks: the worker stopping must not wait for it to wake.
        $emitter = new SseEmitter(static function (SseEmitter $emitter): void {
            while ($emitter->isOpen()) {
                $emitter->push('tick');
                Coroutine::sleep(10);
            }
        });
        $response = new FakeStreamingResponse();
        $started = microtime(true);
        Coroutine\run(static function () use ($emitter, $response): void {
            Coroutine::create(static fn() => $emitter->send($response, new FakeRequest('GET', '/events')));
            Coroutine::sleep(0.05);
            SseEmitter::closeAll();
        });

        self::assertLessThan(1.0, microtime(true) - $started, 'every coroutine of the stream is done');
        self::assertTrue($response->cut, 'the worker stops: the connection is cut');
    }

    public function test_a_client_gone_wakes_a_sleeping_producer(): void
    {
        $emitter = new SseEmitter(static function (SseEmitter $emitter): void {
            while ($emitter->isOpen()) {
                $emitter->push('tick');
                Coroutine::sleep(10);
            }
        }, heartbeat: 0.02);
        $started = microtime(true);
        self::stream($emitter, new FakeStreamingResponse(goneAfter: 3));

        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function test_the_last_event_id_the_browser_sent(): void
    {
        $seen = 'unset';
        $emitter = new SseEmitter(static function (SseEmitter $emitter) use (&$seen): void {
            $seen = $emitter->lastEventId();
        });
        self::stream($emitter, new FakeStreamingResponse(), headers: ['Last-Event-ID' => '41']);

        self::assertSame('41', $seen);
        self::assertNull(new SseEmitter()->lastEventId(), 'not sent yet: none');
    }

    public function test_the_last_event_id_is_there_in_the_controller(): void
    {
        $seen = 'unset';
        Coroutine\run(static function () use (&$seen): void {
            Header::init(new FakeRequest('GET', '/events', ['Last-Event-ID' => '7']));
            $seen = new SseEmitter()->lastEventId();
        });

        self::assertSame('7', $seen, 'what a controller reads to send what was missed');
    }

    public function test_a_backlog_pushed_before_the_stream_is_not_cut(): void
    {
        $emitter = new SseEmitter(capacity: 4);
        for ($i = 1; $i <= 10; $i++) {
            self::assertTrue($emitter->push((string) $i), "replay {$i}");
        }
        $response = new FakeStreamingResponse();
        self::stream($emitter, $response, static function () use ($emitter): void {
            Coroutine::sleep(0.05);
            $emitter->complete();
        });

        self::assertSame(10, substr_count($response->wire(), 'data: '), 'the capacity is for a client too slow, not for a replay');
    }

    public function test_a_stop_does_not_wait_for_a_client_that_stopped_reading(): void
    {
        $closed = false;
        $emitter = new SseEmitter(heartbeat: 0.02);
        $emitter->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        $response = new FakeStreamingResponse(stallAfter: 2);
        $started = microtime(true);
        Coroutine\run(static function () use ($emitter, $response): void {
            Coroutine::create(static fn() => $emitter->send($response, new FakeRequest('GET', '/events')));
            Coroutine::sleep(0.1);
            SseEmitter::closeAll();
        });

        self::assertLessThan(1.0, microtime(true) - $started, 'a full socket does not hold the worker');
        self::assertTrue($closed);
        self::assertTrue($response->cut, 'the connection is cut: what waits in its buffer would hold the stop');
        self::assertFalse($response->ended);
    }

    public function test_a_client_too_slow_is_closed_while_its_write_waits(): void
    {
        $emitter = new SseEmitter(heartbeat: 0.02, capacity: 2);
        $started = microtime(true);
        Coroutine\run(static function () use ($emitter): void {
            Coroutine::create(static fn() => $emitter->send(new FakeStreamingResponse(stallAfter: 2), new FakeRequest('GET', '/events')));
            Coroutine::sleep(0.1);
            foreach (['a', 'b', 'c', 'd'] as $event) {
                $emitter->push($event);
            }
        });

        self::assertLessThan(1.0, microtime(true) - $started);
        self::assertFalse($emitter->isOpen());
    }

    public function test_a_producer_that_catches_everything_is_still_stopped(): void
    {
        $emitter = new SseEmitter(static function (SseEmitter $emitter): void {
            while ($emitter->isOpen()) {
                try {
                    Coroutine::sleep(10);
                } catch (\Throwable) {
                    // a retry loop that swallows everything
                }
            }
        }, heartbeat: 0.02);
        $started = microtime(true);
        self::stream($emitter, new FakeStreamingResponse(goneAfter: 3));

        self::assertLessThan(3.0, microtime(true) - $started, 'every coroutine of the stream is done');
    }

    public function test_on_close_can_wait_when_the_route_deadline_ended_the_stream(): void
    {
        $finished = false;
        $emitter = new SseEmitter(heartbeat: 5.0);
        $emitter->onClose(static function () use (&$finished): void {
            Coroutine::sleep(0.2);                    // a write somewhere: "gone offline"
            $finished = true;
        });
        Coroutine\run(static function () use ($emitter): void {
            RequestWatchdog::enable(0.3);
            RequestWatchdog::register(0.3);
            $emitter->send(new FakeStreamingResponse(), new FakeRequest('GET', '/events'));
            RequestWatchdog::disable();
        });

        self::assertTrue($finished, 'the deadline ends the stream, not its onClose');
    }

    public function test_on_close_runs_when_the_stream_is_never_sent(): void
    {
        $closed = 0;
        Coroutine\run(static function () use (&$closed): void {
            Coroutine::create(static function () use (&$closed): void {
                $emitter = new SseEmitter();
                $emitter->onClose(static function () use (&$closed): void {
                    $closed++;
                });
                // the controller threw, the deadline answered 504, a middleware replaced it…
            });
        });

        self::assertSame(1, $closed, 'a registry that joined it forgets it');
    }

    public function test_a_producer_finishes_its_work_after_it_completes_the_stream(): void
    {
        $done = false;
        $emitter = new SseEmitter(static function (SseEmitter $emitter) use (&$done): void {
            $emitter->push('last');
            $emitter->complete();
            Coroutine::sleep(0.05);                   // its own clean-up
            $done = true;
        });
        self::stream($emitter, new FakeStreamingResponse());

        self::assertTrue($done);
    }

    public function test_head_gets_the_headers_and_no_stream(): void
    {
        $emitter = new SseEmitter();
        $response = new FakeStreamingResponse();
        self::stream($emitter, $response, method: 'HEAD');

        self::assertSame('text/event-stream; charset=utf-8', $response->headers['content-type'] ?? null);
        self::assertSame([], $response->chunks);
        self::assertTrue($response->ended);
    }

    public function test_a_response_that_cannot_stream_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        new SseEmitter()->send(new FakeResponse(), new FakeRequest('GET', '/events'));
    }
}
