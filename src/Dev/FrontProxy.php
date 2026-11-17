<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Dev;

use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleRequest;
use Flytachi\Winter\Kernel\Http\Adapter\SwooleResponse;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Front\SiteMap;
use Flytachi\Winter\Kernel\Route\Router;

/**
 * A frontend's request handed to its development server under `call run dev`
 * ({@see \Flytachi\Winter\Kernel\App\FrontProgram}), on its loopback port, with the
 * coroutine HTTP client: the request as it came — less the headers that belong to one
 * connection — and the answer as it comes back.
 *
 * The program sees who asked: `X-Forwarded-For` / `-Proto` / `-Host` / `-Port`. It is
 * asked as `localhost`: a development server refuses a name it does not know.
 *
 * It is asked for a plain body (`Accept-Encoding: identity`): the HTTP server compresses
 * for the client, as it does a controller's answer. A program that compresses anyway has
 * its body unpacked by the client on the way — gzip, deflate, br — and sent on plain.
 *
 * A program not listening — still starting, or down — answers 503 with `Retry-After`; one
 * that does not answer within the request's time, 504.
 *
 * @internal Between the router and a frontend's program.
 */
final class FrontProxy
{
    /** The headers of one connection, not of the request: never passed on. */
    private const array HOP = [
        'connection', 'keep-alive', 'proxy-connection', 'proxy-authenticate', 'proxy-authorization',
        'te', 'trailer', 'transfer-encoding', 'upgrade', 'content-length', 'host',
    ];

    /**
     * The mark on every request forwarded to a development server: one that comes back to
     * the application with it — a program's proxy hands its request's headers on — finds a
     * controller or the application's 404, and is never forwarded again. No loop.
     */
    public const string MARK = 'x-winter-forwarded';

    /** What RFC 6455 joins to a client's key to accept its socket. */
    private const string WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var array<int, \Swoole\Coroutine\Http\Client> A relayed socket's program side, by the client's fd. */
    private static array $sockets = [];

    /** Seconds a program has to answer — the server's request timeout, set per worker; -1: no limit. */
    private static float $timeout = 30.0;

    /** @param float $seconds The request timeout; 0 — none, and the program has no limit either. */
    public static function timeout(float $seconds): void
    {
        self::$timeout = $seconds > 0 ? $seconds : -1.0;
    }

    /** Whether the request was forwarded to a development server already. */
    public static function cameBack(HttpRequest $request): bool
    {
        return $request->getHeader(self::MARK) !== null;
    }

    public static function forward(FrontSite $site, HttpRequest $request, HttpResponse $response): void
    {
        $upstream = $site->upstream;
        if ($upstream === null || !$request instanceof SwooleRequest || !$response instanceof SwooleResponse) {
            self::unavailable($response, 'the frontend has no server here');
            return;
        }
        $in = $request->getSwooleRequest();
        $out = $response->getSwooleResponse();

        $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $upstream->port);
        $client->set(['timeout' => self::$timeout, 'keep_alive' => false]);
        $client->setMethod($request->getMethod());
        $client->setHeaders(self::requestHeaders($request, $in, "localhost:{$upstream->port}"));
        $body = $request->getRawBody();
        if ($body !== '') {
            $client->setData($body);
        }
        $query = $request->getQueryString();
        $client->execute($request->getPath() . ($query !== null && $query !== '' ? '?' . $query : ''));

        $status = (int) $client->statusCode;
        if ($status <= 0) {
            $client->close();
            // -2: no answer in time; -1 or -3: nobody listening, or gone before answering.
            $status === SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT
                ? self::failed($response, 504, 'the frontend did not answer in time')
                : self::unavailable($response, 'the frontend is starting');
            return;
        }

        $out->status($status);
        $encoding = strtolower(trim((string) ($client->headers['content-encoding'] ?? '')));
        // What the client unpacked is plain now: its encoding no longer says the truth.
        $unpacked = in_array($encoding, self::unpacks(), true);
        $encoded = $encoding !== '' && !$unpacked;
        foreach ((array) $client->headers as $name => $value) {
            $name = strtolower((string) $name);
            $dropped = $name === 'set-cookie' || ($unpacked && $name === 'content-encoding');
            if ($dropped || in_array($name, self::HOP, true)) {
                continue;
            }
            $out->header($name, (string) $value);
        }
        if ($client->set_cookie_headers !== null && $client->set_cookie_headers !== []) {
            $out->header('Set-Cookie', array_values((array) $client->set_cookie_headers));
        }
        $answer = (string) $client->body;
        $client->close();
        if ($request->getMethod() === 'HEAD') {
            // Swoole would measure the empty body: the program's length is the one meant.
            if (!$encoded) {
                $out->header('Content-Encoding', 'identity');
            }
            $length = $client->headers['content-length'] ?? null;
            if ($length !== null && !$unpacked) {
                $out->header('Content-Length', (string) $length);
            }
            $out->end('');
            return;
        }
        // Plain: Swoole compresses it for the client when it may; an encoding the client
        // could not unpack goes on as it came, its header with it.
        $out->end($answer);
    }

    /**
     * A WebSocket asked for on a development server's path — its live reload (HMR): opened
     * to the program first, with what the browser asked for, and accepted with what the
     * program agreed to; from then on what either side says reaches the other
     * ({@see message()}, {@see closed()}). Anywhere else — a controller's path, a path no
     * frontend has, a frontend without a development server — refused with 404.
     *
     * @return bool Whether the socket is open.
     */
    public static function handshake(
        \Swoole\WebSocket\Server $server,
        SiteMap $sites,
        Router $router,
        \Swoole\Http\Request $request,
        \Swoole\Http\Response $response,
    ): bool {
        $port = (int) ($request->server['server_port'] ?? 0);
        $path = (string) ($request->server['request_uri'] ?? '/');
        $site = $sites->siteFor($port, $path);
        $upstream = $site?->upstream;
        $routed = $sites->routeVisible($port, $path) && $router->dispatch('GET', $path)->isFound();
        $back = isset($request->header[self::MARK]);
        if ($upstream === null || $routed || $back || $sites->isRouteOf($port, $path)) {
            $response->status(404);
            $response->end();
            return false;
        }

        $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $upstream->port);
        $client->set(['timeout' => 5]);
        $headers = ['host' => "localhost:{$upstream->port}"];
        foreach ((array) $request->header as $name => $value) {
            $name = strtolower((string) $name);
            if (in_array($name, ['origin', 'cookie', 'user-agent', 'sec-websocket-protocol'], true)) {
                $headers[$name] = (string) $value;
            }
        }
        $client->setHeaders($headers);
        $query = (string) ($request->server['query_string'] ?? '');
        if (!$client->upgrade($path . ($query !== '' ? "?{$query}" : ''))) {
            $status = (int) $client->statusCode;
            $client->close();
            $response->status($status > 0 ? $status : 503);
            $response->end();
            return false;
        }

        $key = (string) ($request->header['sec-websocket-key'] ?? '');
        $response->header('Upgrade', 'websocket');
        $response->header('Connection', 'Upgrade');
        $response->header('Sec-WebSocket-Accept', base64_encode(sha1($key . self::WEBSOCKET_GUID, true)));
        $protocol = $client->headers['sec-websocket-protocol'] ?? null;
        if ($protocol !== null) {
            $response->header('Sec-WebSocket-Protocol', (string) $protocol);
        }
        $response->status(101);
        $response->end();

        $fd = $request->fd;
        self::$sockets[$fd] = $client;
        \Swoole\Coroutine::create(static function () use ($server, $client, $fd): void {
            while (true) {
                $frame = $client->recv(-1);
                if (!$frame instanceof \Swoole\WebSocket\Frame || $frame->opcode === WEBSOCKET_OPCODE_CLOSE) {
                    break;
                }
                if (!$server->isEstablished($fd)) {
                    break;
                }
                $server->push($fd, $frame->data, $frame->opcode);
            }
            if ((self::$sockets[$fd] ?? null) === $client) {
                unset(self::$sockets[$fd]);
                $client->close();
                if ($server->exist($fd)) {
                    $server->disconnect($fd);
                }
            }
        });
        return true;
    }

    /** What the browser said on a relayed socket, passed to the program. */
    public static function message(\Swoole\WebSocket\Frame $frame): void
    {
        self::$sockets[$frame->fd]?->push($frame->data, $frame->opcode);
    }

    /** A connection closed — a relayed socket's program side goes with it. */
    public static function closed(int $fd): void
    {
        $client = self::$sockets[$fd] ?? null;
        if ($client !== null) {
            unset(self::$sockets[$fd]);
            $client->close();
        }
    }

    /**
     * The request's headers as the program gets them.
     *
     * @return array<string, string>
     */
    private static function requestHeaders(HttpRequest $request, \Swoole\Http\Request $in, string $host): array
    {
        $headers = [];
        foreach ((array) $in->header as $name => $value) {
            if (!in_array(strtolower((string) $name), self::HOP, true)) {
                $headers[(string) $name] = (string) $value;
            }
        }
        $original = (string) ($in->header['host'] ?? '');
        $peer = (string) ($in->server['remote_addr'] ?? '');
        $forwardedFor = trim((string) ($in->header['x-forwarded-for'] ?? ''));
        $headers['host'] = $host;
        $headers['accept-encoding'] = 'identity';
        $headers['x-forwarded-for'] = $forwardedFor === '' ? $peer : "{$forwardedFor}, {$peer}";
        $headers['x-forwarded-proto'] = $request->getScheme();
        $headers['x-forwarded-host'] = $original;
        $headers['x-forwarded-port'] = (string) ($in->server['server_port'] ?? '');
        $headers[self::MARK] = '1';
        return $headers;
    }

    /** @return list<string> The encodings the coroutine client unpacks on its own. */
    private static function unpacks(): array
    {
        $brotli = defined('SWOOLE_HAVE_BROTLI') && SWOOLE_HAVE_BROTLI;
        return $brotli ? ['gzip', 'deflate', 'br'] : ['gzip', 'deflate'];
    }

    private static function unavailable(HttpResponse $response, string $why): void
    {
        $response->header('Retry-After', '2');
        self::failed($response, 503, $why);
    }

    private static function failed(HttpResponse $response, int $status, string $why): void
    {
        $response->status($status);
        $response->header('Content-Type', 'text/plain; charset=utf-8');
        $response->header('Cache-Control', 'no-store');
        $response->end($why);
    }
}
