<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Response\ResponseException;
use Flytachi\Winter\Kernel\WebSocket\Connection;
use Flytachi\Winter\Kernel\WebSocket\Frame;
use Flytachi\Winter\Kernel\WebSocket\WebSocket;

/**
 * A socket driven by its messages, for the tests: every behaviour under test is one
 * command away. Lifecycle events are appended to the file named by `WS_LOG`;
 * `WS_HEARTBEAT` and `WS_MAX` tune the heartbeat and the connection limit.
 */
final class EchoSocket extends WebSocket
{
    protected ?string $path = '/ws';
    protected int $maxFrameSize = 65_536;
    protected float $grace = 5.0;

    protected function onStart(): void
    {
        $this->heartbeat = (float) (getenv('WS_HEARTBEAT') ?: 30.0);
        $this->maxConnections = (int) (getenv('WS_MAX') ?: 0);
        self::log('start');
    }

    protected function onHandshake(Connection $conn, HttpRequest $request): bool
    {
        $token = $request->getQueryParams()['token'] ?? '';
        if ($token === 'expired') {
            throw new ResponseException('token expired', HttpCode::UNAUTHORIZED);
        }
        if ($token === 'boom') {
            throw new \RuntimeException('handshake exploded');
        }
        if ($token === 'send') {
            try {
                $conn->send('too early');
            } catch (\LogicException) {
                $conn->set('refused', true);
            }
        }
        $conn->set('name', $request->getQueryParams()['name'] ?? 'anon');
        return $token !== 'bad';
    }

    protected function onOpen(Connection $conn): void
    {
        $conn->send($conn->has('refused') ? 'refused-in-handshake' : 'welcome ' . $conn->get('name'));
    }

    protected function onMessage(Connection $conn, Frame $frame): void
    {
        [$command, $arg] = explode(':', $frame->data, 2) + [1 => ''];
        match ($command) {
            'echo'  => $conn->send($arg),
            'all'   => $conn->send('sent:' . $this->broadcast($arg, except: $conn)),
            'count' => $conn->send('count:' . $this->count()),
            'boom'  => throw new \RuntimeException('message exploded'),
            'close' => $conn->close(4001, 'asked'),
            'slow'  => $this->slow($conn),
            'storm' => $this->storm($conn),
            'bin'   => $conn->send($frame->isBinary ? 'binary' : 'text'),
            default => $conn->send('unknown'),
        };
    }

    protected function onClose(Connection $conn, int $code, string $reason): void
    {
        self::log("close {$conn->get('name')} {$code} {$reason}");
    }

    protected function onError(Connection $conn, \Throwable $e): void
    {
        parent::onError($conn, $e);
        self::log("error {$e->getMessage()}");
    }

    /** A hook that waits on I/O: must not hold up any other connection. */
    private function slow(Connection $conn): void
    {
        usleep(300_000);
        $conn->send('slow done');
    }

    /** Many coroutines writing one socket at once — fatal without the single writer. */
    private function storm(Connection $conn): void
    {
        $wg = new \Swoole\Coroutine\WaitGroup();
        for ($i = 0; $i < 50; $i++) {
            $wg->add();
            \Swoole\Coroutine::create(function () use ($conn, $wg, $i): void {
                $conn->send("storm {$i}");
                $wg->done();
            });
        }
        $wg->wait();
        $this->broadcast('storm broadcast');
        $conn->send('storm end');
    }

    private static function log(string $line): void
    {
        $file = getenv('WS_LOG');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}
