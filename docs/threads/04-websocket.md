# WebSocket — TCP WebSocket server

A `WebSocket` is a `Dispatchable` that owns a `stream_socket_server`
bound to a TCP port, performs the RFC 6455 handshake on accept, and
exposes three abstract hooks for connect / message / disconnect.

It runs single-process (no fork inside the server loop) — concurrency
comes from `stream_select()` multiplexing.

---

## Stereotype

```php
namespace App\Threads\WebSockets;

use Flytachi\Winter\K2\Stereotype\WebSocket;
use Flytachi\Winter\K2\Process\Socket\Web\PDU\Msg;
use Flytachi\Winter\K2\Process\Socket\Web\PDU\WSResource;

class Chat extends WebSocket
{
    protected string $ip   = '0.0.0.0';
    protected int    $port = 9001;

    protected function handleConnect(WSResource $resource): void
    {
        $this->logger->info("Joined: {$resource}");
        $this->send($resource, 'Welcome');
    }

    protected function handle(WSResource $resource, Msg $msg): void
    {
        if ($msg->type === 'text') {
            // broadcast to everyone else
            foreach ($this->connects as $peer) {
                if ($peer !== $resource) {
                    $this->send($peer, $msg->payload);
                }
            }
        }
    }

    protected function handleDisconnect(WSResource $resource): void
    {
        $this->logger->info("Left: {$resource}");
    }
}
```

`WebSocket extends ThreadWebSocket extends Dispatch`.
`exNamespace = 'web-socket'`.

DI works identically — `Container::make()` instantiates the class,
`#[Autowired]` resolves.

---

## Running

| Call                                                            | Behavior |
|-----------------------------------------------------------------|----------|
| `Chat::start(['ip' => '0.0.0.0', 'port' => 9001])`              | Foreground |
| `Chat::dispatch(['ip' => '0.0.0.0', 'port' => 9001])`           | Background fork; returns PID |
| `call thread run app.threads.websockets.Chat`                   | CLI foreground |
| `call thread run app.threads.websockets.Chat -d`                | CLI background |

The optional `$data` map is read by `resolution()` and overrides the
`$ip` / `$port` properties for that run, so the same class can be
bound to different ports without subclassing:

```php
Chat::dispatch(['port' => 9002]);
Chat::dispatch(['port' => 9003]);
```

There is **no cluster lock** (unlike `Daemon`). If you need a
singleton bind per machine, wrap launching in a `Daemon` or let the
supervisor enforce it.

---

## Abstract hooks

Every concrete `WebSocket` must implement three methods. The server
loop catches `\Throwable` around each one and logs it — your code
won't take the server down.

```php
abstract protected function handleConnect(WSResource $resource): void;
abstract protected function handle(WSResource $resource, Msg $msg): void;
abstract protected function handleDisconnect(WSResource $resource): void;
```

| Hook                | Fires when                                           |
|---------------------|------------------------------------------------------|
| `handleConnect()`   | After successful handshake; `$resource` registered   |
| `handle()`          | A complete frame was decoded — `$msg->type` ∈ `text / binary / ping / pong` |
| `handleDisconnect()`| Before the connection is torn down (EOF, error frame, close, server stop) |

Note `close` and `error` frames trigger `disconnectClient()` directly —
your `handle()` does not see them. Errors are logged as warnings;
malformed/unmasked frames are surfaced as a synthetic error msg and
the connection is closed.

---

## Sending

```php
public function send(WSResource $resource, string $payload, string $type = 'text'): void
```

- Frames the payload via `WebSocketProtocol::encode()` and **queues**
  it on the resource's `writeBuffer`.
- The main loop flushes queued bytes when the connection is writable
  (`stream_select` returns it in `$write`).
- Sending to a disconnected client is a no-op + warning log.

Types accepted by `encode()`: `text` (default), `binary`, `ping`,
`pong`, `close`. Use `text` / `binary` from `send()`; `close` is
emitted internally by `disconnectClient()`.

To broadcast, loop `$this->connects` (a `[string => WSResource]` map
keyed by the stream resource ID).

---

## The server loop

`ThreadWebSocket::resolution()` is `final` — you don't override it.
It does:

```
bind:     stream_socket_server("tcp://$ip:$port", STREAM_SERVER_{BIND,LISTEN})
nonblock: stream_set_blocking(false)

while true:
    select($read = clients + listen-sock, $write = those with queued bytes,
           timeout = $loopInterval µs)

    if listener has activity:
        accept new connection
        try handshake → on success register WSResource, fire handleConnect()
                       on failure send "HTTP/1.1 400 Bad Request" and close

    for each readable client:
        fread(65535)
        if EOF: disconnectClient()
        append to readBuffer
        while a complete frame can be decoded:
            consume bytes
            if close/error frame → disconnectClient()
            else → fire handle($resource, $msg)

    for each writable client:
        fwrite(writeBuffer) — track partial writes

    if $timeWorkLimit > 0 and elapsed > limit: break the loop

    pcntl_signal_dispatch()
    loop()             ← optional override hook
```

---

## Override hooks

| Member / hook                       | Purpose |
|-------------------------------------|---------|
| `$ip` / `$port`                     | Default bind (overridable per call via `$data`) |
| `$loopInterval` (µs, default `200_000`) | `stream_select` timeout — keeps `loop()` ticking on quiet sockets |
| `$timeWorkLimit` (s, default `0`)   | If > 0, the loop exits cleanly after that many seconds |
| `protected function loop(): void`   | Called once per iteration after I/O — use it for timers, periodic broadcasts, GC; default no-op |
| `handleConnect()` / `handle()` / `handleDisconnect()` | The three required event hooks |
| `asInterrupt()` / `asTermination()` / `asClose()` | Signal log overrides |

A `loop()` example — broadcast a heartbeat every 5 seconds:

```php
private int $lastBeat = 0;

protected function loop(): void
{
    if (time() - $this->lastBeat >= 5) {
        foreach ($this->connects as $peer) {
            $this->send($peer, json_encode(['t' => time()]));
        }
        $this->lastBeat = time();
    }
}
```

---

## `WSResource` and `Msg`

`WSResource` (`src/Process/Socket/Web/PDU/WSResource.php`) wraps one
connection — exposes the underlying stream (`getConnect()`), the
handshake info (URI, headers, query params, ip/port), and the
`readBuffer` / `writeBuffer` strings the loop manipulates. Casting it
to `(string)` returns a unique connection ID — that's also the key in
`$this->connects`.

`Msg` carries the decoded frame: `type`, `payload`, and (for synthetic
errors) an error message.

---

## Signals

`SocketWebServerHandler` is the per-WebSocket signal trait:

| Signal   | Internal handler   | Override          | Default log |
|----------|--------------------|-------------------|-------------|
| `SIGHUP` | `signClose()`      | `asClose()`       | `notice "CLOSE"` |
| `SIGINT` | `signInterrupt()`  | `asInterrupt()`   | `notice "INTERRUPTED"` |
| `SIGTERM`| `signTermination()`| `asTermination()` | `warning "TERMINATION"` |

Each path calls `resolutionEnd()` first, which calls `socketClose()` —
that disconnects every client (sending a 1000 close frame) and shuts
the listener. Clean.

`pcntl_signal_dispatch()` is invoked once per iteration, so signals
deliver promptly even on quiet sockets.

---

## Errors

- Bind failure or `stream_socket_server` returning `false` → critical
  log + clean shutdown (`socketClose()`).
- Per-hook throws — caught at the call site (each of
  `handleConnect`/`handle`/`handleDisconnect`), logged, the loop
  continues.
- Frame decode errors — synthetic `'error'` `Msg` produced; the loop
  warns and closes the offending connection (it does not propagate to
  `handle()`).

---

## Examples

```php
// Echo server
class Echo extends WebSocket
{
    protected function handleConnect(WSResource $r): void {}
    protected function handle(WSResource $r, Msg $m): void
    {
        $this->send($r, $m->payload);
    }
    protected function handleDisconnect(WSResource $r): void {}
}

Echo::dispatch(['port' => 9001]);
```

```php
// Path-aware routing — split traffic by URI
protected function handleConnect(WSResource $r): void
{
    $path = $r->info['path'] ?? '/';
    if ($path !== '/chat') {
        $this->disconnectClient($r);     // close non-/chat clients
    }
}
```

```php
// Time-limited test server
class StressTest extends WebSocket
{
    protected int $timeWorkLimit = 60;   // shuts itself down after 60s
    // ...
}
```

---

## When NOT to use this

- For high-throughput production WebSocket workloads, prefer Swoole's
  built-in WebSocket server (it's coroutine-aware and works with the
  HTTP server you already run via `call run`). The kernel ships
  `ThreadWebSocket` as a self-contained, dependency-free alternative —
  great for internal services, tests, sidecar protocols. It is **not**
  designed to compete with Swoole / Workerman at scale.
- For request/response semantics use a normal `Controller`.

---

## Source

- `src/Stereotype/WebSocket.php`
- `src/Process/Socket/Web/ThreadWebSocket.php`
- `src/Process/Socket/Web/WebSocketProtocol.php`
- `src/Process/Socket/Web/SocketWebServerHandler.php`
- `src/Process/Socket/Web/PDU/*` (`WSResource`, `Msg`, `DecodedFrame`)
- `src/Process/Core/Dispatch.php`, `Dispatchable.php`

## See also

- [00-overview.md](00-overview.md) — `Dispatch` lifecycle and DI
- [03-daemon.md](03-daemon.md) — to make the server a supervised singleton
- [`../console/09-thread.md`](../console/09-thread.md) — `call thread run`
- [`../console/02-make.md`](../console/02-make.md) — scaffold with `-W`
