<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route\Fixtures\App;

use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestParam;
use Flytachi\Winter\Kernel\Http\Sse\SseEmitter;
use Flytachi\Winter\Kernel\Http\Sse\SseEvent;
use Flytachi\Winter\Kernel\Http\Stereotype\Controller;
use Flytachi\Winter\Kernel\Route\Annotation\GetMapping;
use Flytachi\Winter\Kernel\Route\Annotation\PostMapping;
use Flytachi\Winter\Kernel\Route\Annotation\RequestMapping;
use Flytachi\Winter\Kernel\Route\Annotation\Timeout;

/** Server-Sent Events over real HTTP, for {@see \Flytachi\Winter\Kernel\Tests\Route\ServeSseTest}. */
#[RequestMapping('/sse')]
final class StreamController extends Controller
{
    /** @var array<int, SseEmitter> The board's listeners — one worker, so one list. */
    private static array $listeners = [];

    /** A stream that makes its own events: a tick every 0.2 s while it is open. */
    #[GetMapping('/clock')]
    public function clock(): SseEmitter
    {
        return new SseEmitter(static function (SseEmitter $emitter): void {
            for ($i = 1; $emitter->isOpen(); $i++) {
                $emitter->push(new SseEvent("tick {$i}", event: 'tick', id: (string) $i));
                \Swoole\Coroutine::sleep(0.2);
            }
        }, heartbeat: 0.3);
    }

    /** A stream other requests push to. */
    #[GetMapping('/board')]
    public function board(): SseEmitter
    {
        $emitter = new SseEmitter(heartbeat: 0.3);
        $id = spl_object_id($emitter);
        self::$listeners[$id] = $emitter;
        $emitter->onClose(static function () use ($id): void {
            unset(self::$listeners[$id]);
        });
        return $emitter;
    }

    #[PostMapping('/board')]
    public function say(#[RequestParam] string $text): array
    {
        foreach (self::$listeners as $emitter) {
            $emitter->push(new SseEvent($text, event: 'message'));
        }
        return ['listeners' => count(self::$listeners)];
    }

    #[GetMapping('/listeners')]
    public function listeners(): array
    {
        return ['listeners' => count(self::$listeners)];
    }

    /** A stream that writes as fast as it can: a client that stops reading fills its socket. */
    #[GetMapping('/flood')]
    public function flood(): SseEmitter
    {
        return new SseEmitter(static function (SseEmitter $emitter): void {
            $big = str_repeat('x', 65536);
            while ($emitter->isOpen()) {
                $emitter->push($big);
                \Swoole\Coroutine::sleep(0.005);
            }
        }, capacity: 100000);
    }

    /** A stream with a deadline of its own: it ends after 2 s. */
    #[Timeout(2)]
    #[GetMapping('/capped')]
    public function capped(): SseEmitter
    {
        return new SseEmitter(heartbeat: 0.3);
    }
}
