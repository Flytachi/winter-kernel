# Winter WebSocket — Overview

HTTP is a conversation the client always starts: it asks, the server answers, and
the exchange is over. A chat, a live dashboard, a notification bell or a
multiplayer board need the other direction too — the server has something to say
*now*, and the client did not ask. Polling every second fakes it at the cost of a
request per client per second; WebSocket solves it properly. After an ordinary HTTP
request that asks to *upgrade*, the same TCP connection stays open and both sides
send messages over it whenever they want, for as long as it lives.

**WebSocket** in Winter is a stereotype built on {@see Process}: a long-running
process that is a WebSocket server. You write hooks — who may connect, what to do
with a message, what to do when someone leaves — and the framework runs the server,
the connections, the heartbeat and the graceful stop.

```php
final class ChatSocket extends WebSocket
{
    protected function onMessage(Connection $conn, Frame $frame): void
    {
        $this->broadcast($frame->data, except: $conn);
    }
}
```

```php
#[EnableWeb]
#[EnableWebSocket(ChatSocket::class, port: 8001)]
final class App extends WinterApplication {}
```

That is a working chat: anyone may connect, every message goes to everyone else.

Needs ext-swoole.

---

## Running it

A port is always required, and one port serves one process.

| How | Command |
|---|---|
| With the application | `#[EnableWebSocket(ChatSocket::class, port: 8001)]` on the App class, then `call run` |
| Standalone, foreground | `call process main.ChatSocket start --port=8001 [--host=127.0.0.1]` |
| Standalone, background | `call process main.ChatSocket start --port=8001 -d` |
| From code, background | `ChatSocket::dispatch(port: 8001)` (named arguments) |
| Status / stop | `call process main.ChatSocket status` / `stop` |

`host` defaults to `0.0.0.0`. Without a port the start is refused with
`WebSocketConfigException`. `#[EnableProcess(ChatSocket::class)]` is refused at boot
(it has nowhere to carry the port); a WebSocket on the web server's port, or two on
one port, are refused at boot too.

Two processes cannot share a port by path: the kernel hands a connection to a process
before its path is read. Route by path in the proxy:

```nginx
location /ws/chat {
    proxy_pass http://app:8001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
}
```

---

## Settings (properties)

| Property | Default | Meaning |
|---|---|---|
| `?string $path` | `null` | the one accepted request path; others get 404. `null` accepts any |
| `?array $allowedOrigins` | `null` | browser origins allowed to connect; another `Origin` gets 403 before `onHandshake()`, a request without one passes. `null` = no check. Set it whenever the socket trusts session cookies (cross-site WebSocket hijacking) |
| `float $heartbeat` | `30.0` | seconds between pings; no answer by the next one → close 1001. `0` = off |
| `int $maxConnections` | `0` | open connections allowed; one more gets 503 before the upgrade. `0` = no limit |
| `int $maxFrameSize` | `1048576` | largest message in bytes; bigger → close 1009 |
| `float $grace` | from Process | seconds the stop waits for running hooks; `0` = for good |

## Hooks

| Hook | When | Default |
|---|---|---|
| `onStart(): void` | once, before the first connection is accepted; awaited | nothing |
| `onHandshake(Connection, HttpRequest): bool` | before the upgrade, still HTTP | `true` |
| `onOpen(Connection): void` | after the upgrade; sending works | nothing |
| `onMessage(Connection, Frame): void` | each text/binary message | **abstract** |
| `onClose(Connection, int $code, string $reason): void` | the connection ended, any cause | nothing |
| `onError(Connection, Throwable): void` | a hook threw | log, keep the connection |

- `onStart`: start anything that loops forever with `spawn()`, or the server never
  accepts a client. Throwing stops the process.
- `onHandshake`: `false` → 403; a thrown `ResponseException` → its status (same
  mapping as the router, `#[AdviceException]` included); any other exception → 500 and
  `onError`. `Connection::send()` throws `LogicException` here; `set()` works.
- `onClose` runs only for upgraded connections. Codes: 1000 normal, 1001 going away
  (stop, heartbeat), 1005 closed without a code, 1006 dropped without a close, 1009 too
  big, or the client's own.
- `run()` is final.

## Primitives on `$this`

| Method | Returns | Meaning |
|---|---|---|
| `broadcast(string $data, ?Connection $except = null)` | `int` queued | text to every open connection of **this process** |
| `connections()` | `array<int, Connection>` | open connections keyed by id |
| `connection(int $id)` | `?Connection` | one by id |
| `count()` | `int` | open now |

Plus everything from Process: `spawn()`, `sleep()`, `isRunning()`, `$logger`, signal
hooks, `onShutdown()`.

## `Connection` and `Frame`

`Connection`: `id`, `remoteAddress`, `request(): HttpRequest`, `send(string): bool`,
`sendBinary(string): bool`, `close(int $code = 1000, string $reason = '')`,
`isOpen()`, `set()` / `get()` / `has()` — per-connection state, dropped on close.

`send()` queues and returns at once. `false`: closed or closing, or the client stopped
reading and its queue (1024 messages) is full — the message is dropped.

`Frame`: `data` (string), `isBinary` (bool). Ping, pong and close never reach
`onMessage`.

---

## How it works

- `run()` binds `Swoole\Coroutine\Http\Server`, calls `onStart()`, then `start()`.
- **One connection, one coroutine** (the reader). Only it reads.
- **One writer per connection** (`Internal\Wire`): every outgoing frame — data, pong,
  ping, close — goes through a queue drained by one coroutine. Swoole kills the whole
  process with a fatal error when two coroutines write one socket at once; a broadcast
  meeting a reply would be enough. The queue also keeps order and lets a slow client
  fill its own queue instead of stalling the sender.
- **Swoole's automatic pong is off** (`open_websocket_ping_frame`): it writes from
  inside `recv()` and would race the writer. The reader queues the pong.
- **Every hook call runs in its own short coroutine.** A pooled DB/Redis connection is
  held until the coroutine that borrowed it ends; borrowed in the connection's
  coroutine it would be held for the life of the socket. Per hook call it goes back
  like at the end of an HTTP request; `#[Request]` beans are scoped the same way.
- **Heartbeat** without a timer: `recv()` waits until the next ping is due; a timeout
  queues a ping; a second timeout with no frame from the client closes it. Any frame
  counts as alive.
- **Too big**: `package_max_length = maxFrameSize + 14`; Swoole fails the read with
  `SWOOLE_ERROR_PACKAGE_LENGTH_TOO_LARGE` → close 1009.
- **Stop**: SIGTERM cancels the body, so `start()` returns *before* `onTerminate()`.
  `run()` then closes every connection with 1001, and waits for running handlers up to
  `$grace`.
- A request without `Upgrade: websocket` gets 426.
- Under `call run` the process is a companion (`addProcess`) and inherits the coroutine
  hooks, so blocking I/O in a hook yields instead of stalling every connection.

## Not here (yet)

- several processes behind one declaration (Daemon, `ports: [...]`);
- port from `.env`;
- connection count in `call process … status`.

Design and the experiments behind it: `docs/2026-10-15-websocket-design.md`.
