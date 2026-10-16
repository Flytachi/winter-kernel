<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\WebSocket;

use Flytachi\Winter\Base\Exception\ExceptionLogLevel;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Response\ExceptionWrapper;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;
use Flytachi\Winter\Kernel\WebSocket\Internal\Wire;
use Psr\Log\LogLevel;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Server;
use Swoole\Coroutine\WaitGroup;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * A WebSocket server as a {@see Process}: you write the hooks, the framework runs the
 * server, the connections, the heartbeat and the graceful stop.
 *
 * Every connection is a coroutine of its own, and every hook call runs in a short
 * coroutine of its own too — so a pooled DB or Redis connection taken in a hook goes
 * back to the pool when the hook returns, exactly as at the end of an HTTP request,
 * instead of being held for the life of the socket.
 *
 * ```
 * final class ChatSocket extends WebSocket
 * {
 *     protected function onHandshake(Connection $conn, HttpRequest $request): bool
 *     {
 *         $conn->set('user', $this->auth->user($request));
 *         return $conn->has('user');
 *     }
 *
 *     protected function onMessage(Connection $conn, Frame $frame): void
 *     {
 *         $this->broadcast($frame->data, except: $conn);
 *     }
 * }
 * ```
 *
 * Declared on the application with
 * {@see \Flytachi\Winter\Kernel\App\Attribute\EnableWebSocket}, or run on its own:
 * `call process Main.ChatSocket start --port=8001`, `ChatSocket::dispatch(port: 8001)`.
 * A port is always required; one port serves one process.
 *
 * Needs ext-swoole.
 */
abstract class WebSocket extends Process
{
    /**
     * The one request path accepted, e.g. `/chat`; others get 404. Null accepts any
     * path — usual behind a proxy that already routes by path.
     */
    protected ?string $path = null;

    /**
     * Seconds between pings. A connection that has not answered the previous ping by
     * the next one is closed (1001) — a client that vanished without closing, or one a
     * proxy would cut off for being silent. 0 turns it off.
     */
    protected float $heartbeat = 30.0;

    /** Open connections allowed at once; one more is answered 503 before the upgrade. 0 = no limit. */
    protected int $maxConnections = 0;

    /** Largest message accepted, in bytes; a bigger one closes the connection with 1009. */
    protected int $maxFrameSize = 1_048_576;

    /** Messages queued for one client before {@see Connection::send()} starts dropping them. */
    private const int SEND_QUEUE = 1024;
    /** Room for the frame header on top of the payload (at most 14 bytes). */
    private const int FRAME_HEADER = 14;
    /** How long a closing connection waits for its writer to flush the close frame. */
    private const float CLOSE_FLUSH = 1.0;

    private static ?int $bindPort = null;
    private static string $bindHost = '0.0.0.0';

    private ?Server $server = null;
    private ?WaitGroup $handlers = null;
    /** @var array<int, array{Connection, Wire}> */
    private array $live = [];
    private int $lastId = 0;

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Runs once, before the first connection is accepted — the framework waits for it.
     * The place for preparation and for background work: start anything that loops
     * forever with {@see spawn()}, or the server never starts accepting clients.
     * An exception here stops the process.
     */
    protected function onStart(): void
    {
    }

    /**
     * Decides whether the client gets in. Runs before the upgrade — it is still an
     * HTTP request, so nothing can be sent yet; {@see Connection::set()} works and its
     * values carry over into the connection.
     *
     * Return false for 403; throw a {@see \Flytachi\Winter\Kernel\Http\Response\ResponseException}
     * for an exact status (e.g. 401). Any other exception answers 500 and goes to
     * {@see onError()}.
     */
    protected function onHandshake(Connection $conn, HttpRequest $request): bool
    {
        return true;
    }

    /** The connection is upgraded — sending works from here on. */
    protected function onOpen(Connection $conn): void
    {
    }

    /** A text or binary message from the client. */
    abstract protected function onMessage(Connection $conn, Frame $frame): void;

    /**
     * The connection ended — whoever closed it, for whatever reason. Called only for
     * connections that were upgraded.
     *
     * @param int $code 1000 normal, 1001 going away (stop, heartbeat), 1005 closed without a code,
     *                  1006 dropped without a close, 1009 message too big, or the client's own.
     */
    protected function onClose(Connection $conn, int $code, string $reason): void
    {
    }

    /**
     * An exception escaped a hook. By default it is logged and the connection stays
     * open — override to close it (`$conn->close(1011)`) or to tell the client.
     */
    protected function onError(Connection $conn, \Throwable $e): void
    {
        $this->logger->error($e->getMessage(), [
            'connection' => $conn->id,
            'remote'     => $conn->remoteAddress,
            'exception'  => $e::class,
            'file'       => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Primitives
    // -------------------------------------------------------------------------

    /**
     * Sends a text message to every open connection of this process.
     *
     * Each one is queued on its own connection, so a slow client never delays the rest.
     * Clients of another process or instance are not reached — bridge them yourself
     * (Redis pub/sub in {@see onStart()}).
     *
     * @param Connection|null $except Usually the sender.
     * @return int How many connections it was queued to.
     */
    final protected function broadcast(string $data, ?Connection $except = null): int
    {
        $queued = 0;
        foreach ($this->live as [$conn, $wire]) {
            if ($conn !== $except && $wire->enqueue($data, WEBSOCKET_OPCODE_TEXT)) {
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * The open connections of this process, keyed by id — filter them for rooms or users.
     *
     * @return array<int, Connection>
     */
    final protected function connections(): array
    {
        return array_map(static fn(array $pair): Connection => $pair[0], $this->live);
    }

    /** An open connection by its id, or null. */
    final protected function connection(int $id): ?Connection
    {
        return $this->live[$id][0] ?? null;
    }

    /** How many connections are open right now. */
    final protected function count(): int
    {
        return count($this->live);
    }

    // -------------------------------------------------------------------------
    // Control surface
    // -------------------------------------------------------------------------

    /**
     * Runs the server in the foreground.
     *
     * @param int|null $port Required — the port to listen on.
     * @param string|null $host Interface to bind; `0.0.0.0` when null.
     * @throws WebSocketConfigException No port, or no ext-swoole.
     */
    public static function start(?int $port = null, ?string $host = null): void
    {
        self::bind($port, $host);
        parent::start();
    }

    /**
     * Launches the server detached in the background and returns its PID.
     *
     * ```
     * ChatSocket::dispatch(port: 8001);
     * ```
     *
     * @param string|null $output '/dev/null' (default) or a file path for the child's stdio.
     * @param int|null $port Required — the port to listen on.
     * @param string|null $host Interface to bind; `0.0.0.0` when null.
     * @throws WebSocketConfigException No port, or no ext-swoole.
     */
    public static function dispatch(?string $output = '/dev/null', ?int $port = null, ?string $host = null): int
    {
        self::bind($port, $host);
        return static::dispatchWith(['port' => (string) self::$bindPort, 'host' => self::$bindHost], $output);
    }

    /**
     * {@inheritDoc}
     */
    public static function startWith(array $args): void
    {
        $port = isset($args['port']) && is_numeric($args['port']) ? (int) $args['port'] : null;
        $host = isset($args['host']) && is_string($args['host']) && $args['host'] !== '' ? $args['host'] : null;
        static::start($port, $host);
    }

    /** The port this process listens on, once started. */
    public static function boundPort(): ?int
    {
        return self::$bindPort;
    }

    private static function bind(?int $port, ?string $host): void
    {
        if ($port === null) {
            throw new WebSocketConfigException(sprintf(
                '%s needs a port: #[EnableWebSocket(%s::class, port: …)], `--port=…` or dispatch(port: …).',
                static::class,
                (new \ReflectionClass(static::class))->getShortName(),
            ));
        }
        if ($port < 1 || $port > 65535) {
            throw new WebSocketConfigException(static::class . ": port {$port} is out of range 1–65535.");
        }
        if (!extension_loaded('swoole')) {
            throw new WebSocketConfigException(static::class . ' needs ext-swoole (pecl install swoole).');
        }
        self::$bindPort = $port;
        self::$bindHost = $host ?? '0.0.0.0';
    }

    // -------------------------------------------------------------------------
    // Body
    // -------------------------------------------------------------------------

    /**
     * The server itself — written by the framework.
     */
    final public function run(): void
    {
        $server = new Server(self::$bindHost, (int) self::$bindPort);
        $server->set([
            // Every frame comes to the reader, nothing is answered behind its back:
            // Swoole's automatic pong would write from the reader while the writer
            // writes too — a fatal error for the process.
            'open_websocket_close_frame' => true,
            'open_websocket_ping_frame'  => true,
            'open_websocket_pong_frame'  => true,
            'package_max_length'         => $this->maxFrameSize + self::FRAME_HEADER,
        ]);
        $server->handle('/', function (Request $request, Response $response): void {
            $this->handlers?->add();
            try {
                $this->serve($request, $response);
            } finally {
                $this->handlers?->done();
            }
        });
        $this->server = $server;
        $this->handlers = new WaitGroup();

        $this->invoke(fn() => $this->onStart());

        if ($this->isRunning()) {
            $this->logger->info(sprintf(
                'WebSocket listening on ws://%s:%d%s',
                self::$bindHost,
                self::$bindPort,
                $this->path ?? '',
            ));
            // Returns when a stop is requested: the engine cancels the body.
            $server->start();
        }

        $this->drain();
    }

    /**
     * Stop: no new connections, a 1001 close to every open one, then wait for the
     * handlers still running — up to {@see $grace} seconds, or for good at 0.
     */
    private function drain(): void
    {
        $this->server?->shutdown();
        foreach ($this->live as [, $wire]) {
            $wire->close(1001, 'going away');
        }
        $this->handlers?->wait($this->grace > 0 ? $this->grace : -1);
    }

    /** One accepted socket, from the HTTP request to the end of the connection. */
    private function serve(Request $request, Response $response): void
    {
        if (!$this->isRunning()) {
            $this->refuse($response, 503);
            return;
        }
        $path = (string) ($request->server['request_uri'] ?? '/');
        if ($this->path !== null && $path !== $this->path) {
            $this->refuse($response, 404);
            return;
        }
        if (strtolower((string) ($request->header['upgrade'] ?? '')) !== 'websocket') {
            $response->header('Upgrade', 'websocket');
            $this->refuse($response, 426);
            return;
        }
        if ($this->maxConnections > 0 && count($this->live) >= $this->maxConnections) {
            $this->refuse($response, 503);
            return;
        }

        $wire = new Wire($response, self::SEND_QUEUE);
        $conn = new Connection(
            ++$this->lastId,
            ($request->server['remote_addr'] ?? '?') . ':' . ($request->server['remote_port'] ?? '?'),
            new SwooleRequest($request),
            $wire,
        );

        $accepted = false;
        try {
            $this->invoke(function () use ($conn, &$accepted): void {
                $accepted = $this->onHandshake($conn, $conn->request());
            });
        } catch (\Throwable $e) {
            $this->reject($response, $conn, $e);
            return;
        }
        if (!$accepted) {
            $this->refuse($response, 403);
            return;
        }
        if (!$response->upgrade()) {
            $this->logger->warning("Upgrade failed for {$conn->remoteAddress}.");
            return;
        }

        $wire->start();
        $this->live[$conn->id] = [$conn, $wire];
        try {
            if (!$this->isRunning()) {
                // Upgraded while the stop was draining: the drain did not see it.
                $wire->close(1001, 'going away');
            }
            $this->hook($conn, fn() => $this->onOpen($conn));
            $this->read($conn, $wire, $response);
        } finally {
            unset($this->live[$conn->id]);
            $wire->gone();
            $this->hook($conn, fn() => $this->onClose($conn, $wire->closeCode, $wire->closeReason));
        }
    }

    /**
     * The read side of a connection: frames in, heartbeat out, until the socket ends.
     * Only this coroutine reads; everything written goes through the {@see Wire}.
     */
    private function read(Connection $conn, Wire $wire, Response $response): void
    {
        $interval = $this->heartbeat;
        $nextPing = $interval > 0 ? microtime(true) + $interval : INF;
        $awaitingPong = false;

        while (true) {
            $timeout = $nextPing === INF ? -1 : max(0.001, $nextPing - microtime(true));
            $frame = $response->recv($timeout);

            if (!is_object($frame)) {
                $error = swoole_last_error();
                if ($frame === false && $nextPing !== INF && $error === self::timedOut()) {
                    if ($awaitingPong) {
                        $nextPing = INF;
                        $this->closeAndWait($wire, 1001, 'heartbeat timeout');
                        return;
                    }
                    $wire->enqueue('', WEBSOCKET_OPCODE_PING);
                    $awaitingPong = true;
                    $nextPing = microtime(true) + $interval;
                    continue;
                }
                if ($frame === false && $error === SWOOLE_ERROR_PACKAGE_LENGTH_TOO_LARGE) {
                    // The stream is unreadable past this point: close, do not read on.
                    $this->closeAndWait($wire, 1009, 'message too big');
                    return;
                }
                // The socket ended: the client went away, or our own close completed.
                return;
            }

            // Any frame proves the client is alive.
            $awaitingPong = false;

            switch ($frame->opcode) {
                case WEBSOCKET_OPCODE_PING:
                    $wire->enqueue((string) $frame->data, WEBSOCKET_OPCODE_PONG);
                    break;
                case WEBSOCKET_OPCODE_PONG:
                    break;
                case WEBSOCKET_OPCODE_CLOSE:
                    // Echo the client's close; the writer then shuts the socket and the
                    // next recv() ends the loop. Its code is what onClose() reports.
                    $code = $frame instanceof \Swoole\WebSocket\CloseFrame && $frame->code > 0 ? $frame->code : 1005;
                    $reason = $frame instanceof \Swoole\WebSocket\CloseFrame ? (string) $frame->reason : '';
                    if ($wire->open) {
                        $wire->close($code, $reason);
                    }
                    break;
                default:
                    if ($wire->open) {
                        $message = new Frame((string) $frame->data, $frame->opcode === WEBSOCKET_OPCODE_BINARY);
                        $this->hook($conn, fn() => $this->onMessage($conn, $message));
                    }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Runs a hook in a coroutine of its own and waits for it, rethrowing what it threw.
     * The short coroutine is the point: whatever was borrowed in it — a pooled DB or
     * Redis connection, a request-scoped bean — is released when it ends, per hook
     * call, not held for the life of the socket.
     */
    private function invoke(callable $hook): void
    {
        $done = new Channel(1);
        Coroutine::create(static function () use ($hook, $done): void {
            try {
                $hook();
                $done->push(true);
            } catch (\Throwable $e) {
                $done->push($e);
            }
        });
        $result = $done->pop();
        if ($result instanceof \Throwable) {
            throw $result;
        }
    }

    /** A hook of an open connection: an exception goes to onError(), never further. */
    private function hook(Connection $conn, callable $hook): void
    {
        try {
            $this->invoke($hook);
        } catch (\Throwable $e) {
            $this->error($conn, $e);
        }
    }

    private function error(Connection $conn, \Throwable $e): void
    {
        try {
            $this->invoke(fn() => $this->onError($conn, $e));
        } catch (\Throwable $failure) {
            $this->logger->error('onError() failed: ' . $failure->getMessage(), [
                'connection' => $conn->id,
                'exception'  => $failure::class,
            ]);
        }
    }

    /**
     * onHandshake() threw: answer with the status the exception carries — the same
     * mapping the router uses for a controller, #[AdviceException] handlers included.
     * A 4xx is a decision and is logged at its level; a 5xx is a failure and goes to
     * onError().
     */
    private function reject(Response $response, Connection $conn, \Throwable $e): void
    {
        $status = 500;
        try {
            $wrapped = ExceptionWrapper::wrap($e);
            $body = $wrapped->getBody();
            $status = $wrapped->getHttpCode()->value;
            $response->status($status);
            foreach ($wrapped->getHeader() as $name => $value) {
                $response->header((string) $name, (string) $value);
            }
            $response->end($body);
        } catch (\Throwable) {
            $this->refuse($response, $status);
        }

        if ($status >= 500) {
            $this->error($conn, $e);
            return;
        }
        $level = $e instanceof ExceptionLogLevel ? $e->getLogLevel() : LogLevel::WARNING;
        $this->logger->log($level, 'Handshake refused: ' . $e->getMessage(), [
            'status' => $status,
            'remote' => $conn->remoteAddress,
        ]);
    }

    private function refuse(Response $response, int $status): void
    {
        $response->status($status);
        $response->end();
    }

    /** Closes from the reader's side and gives the writer a moment to put the close frame out. */
    private function closeAndWait(Wire $wire, int $code, string $reason): void
    {
        $wire->close($code, $reason);
        $deadline = microtime(true) + self::CLOSE_FLUSH;
        while ($wire->open && microtime(true) < $deadline) {
            Coroutine::sleep(0.01);
        }
    }

    /** ETIMEDOUT of this platform — what a recv() that ran out of time leaves behind. */
    private static function timedOut(): int
    {
        return defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : (PHP_OS_FAMILY === 'Darwin' ? 60 : 110);
    }
}
