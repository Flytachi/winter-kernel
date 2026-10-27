<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\WebSocket;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\WebSocket\Internal\Wire;

/**
 * One client connection of a {@see WebSocket}.
 *
 * The same object travels through every hook of the connection, from
 * {@see WebSocket::onHandshake()} to {@see WebSocket::onClose()}, so whatever was put
 * in it with {@see set()} is there for the next message and is gone with the
 * connection. The socket handler itself is a singleton of the process — per-connection
 * state belongs here, never in its properties.
 *
 * ```
 * protected function onHandshake(Connection $conn, HttpRequest $request): bool
 * {
 *     $conn->set('user', $this->auth->user($request));
 *     return $conn->has('user');
 * }
 *
 * protected function onMessage(Connection $conn, Frame $frame): void
 * {
 *     $conn->send('echo from ' . $conn->get('user')->name . ': ' . $frame->data);
 * }
 * ```
 *
 * @link https://winterframe.net/docs/websockets#connection The Connection object
 */
final class Connection
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param int $id Unique within the process for its whole life.
     * @param string $remoteAddress The client as `ip:port`, `[ip]:port` for IPv6.
     * @internal Built by the framework for every handshake.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $remoteAddress,
        private readonly HttpRequest $request,
        private readonly Wire $wire,
    ) {
    }

    /**
     * The handshake request — headers, cookies, query — kept for the life of the connection.
     *
     * @link https://winterframe.net/docs/websockets#request request
     */
    public function request(): HttpRequest
    {
        return $this->request;
    }

    /**
     * Sends a text message.
     *
     * Returns at once: the message is queued and written in order by the connection's
     * own writer, so a slow client never holds up the caller.
     *
     * @return bool False when the connection is closed or closing, or the client has
     *              stopped reading and its queue is full (the message is dropped).
     * @throws \LogicException Called in {@see WebSocket::onHandshake()}, before the upgrade.
     *
     * @link https://winterframe.net/docs/websockets#send send
     */
    public function send(string $data): bool
    {
        $this->assertUpgraded();
        return $this->wire->enqueue($data, WEBSOCKET_OPCODE_TEXT);
    }

    /**
     * Sends a binary message — {@see send()} otherwise.
     *
     * @throws \LogicException Called before the upgrade.
     *
     * @link https://winterframe.net/docs/websockets#sendbinary sendBinary
     */
    public function sendBinary(string $data): bool
    {
        $this->assertUpgraded();
        return $this->wire->enqueue($data, WEBSOCKET_OPCODE_BINARY);
    }

    /**
     * Closes the connection: messages sent before still go out, then the close frame.
     * {@see WebSocket::onClose()} follows with this code. A no-op once closed.
     *
     * @param int $code 1000 normal, 1001 going away, 1008 policy violation, 1011 server error, 4000–4999 your own.
     * @param string $reason Short text for the client (at most 123 bytes on the wire).
     *
     * @link https://winterframe.net/docs/websockets#close close and close codes
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->wire->close($code, $reason);
    }

    /**
     * Whether messages can still be sent. False in onHandshake() and from the close on.
     *
     * @link https://winterframe.net/docs/websockets#isopen isOpen
     */
    public function isOpen(): bool
    {
        return $this->wire->open;
    }

    /**
     * Stores a value on this connection; it lives until the connection closes.
     *
     * @link https://winterframe.net/docs/websockets#set-get-has set, get, has
     */
    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * A value stored with {@see set()}, or null.
     *
     * @link https://winterframe.net/docs/websockets#set-get-has set, get, has
     */
    public function get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Whether a value is stored under the key (a stored null counts).
     *
     * @link https://winterframe.net/docs/websockets#set-get-has set, get, has
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    private function assertUpgraded(): void
    {
        if (!$this->wire->started()) {
            throw new \LogicException(
                'Connection is not upgraded yet: nothing can be sent from onHandshake() — send from onOpen().'
            );
        }
    }
}
