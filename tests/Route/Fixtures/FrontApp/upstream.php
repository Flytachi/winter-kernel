<?php

/*
 * A frontend's own server for the tests — what Nitro or `nuxt dev` would be: listens on
 * $HOST:$PORT and says what it got. `/gzip` answers compressed bytes, `/cookies` two
 * cookies, `/hang` never, `/fork` leaves a child behind; a WebSocket on /_nuxt/ echoes.
 */

declare(strict_types=1);

$port = (int) getenv('PORT');
$server = new Swoole\WebSocket\Server((string) getenv('HOST'), $port, SWOOLE_BASE);
$server->set(['worker_num' => 1, 'log_level' => SWOOLE_LOG_WARNING, 'package_max_length' => 16 * 1024 * 1024]);
echo 'listening on ' . getenv('HOST') . ":{$port} backend=" . getenv('WINTER_BACKEND') . "\n";

$server->on('handshake', static function (Swoole\Http\Request $req, Swoole\Http\Response $res): bool {
    $key = base64_encode(sha1(($req->header['sec-websocket-key'] ?? '') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $res->header('Upgrade', 'websocket');
    $res->header('Connection', 'Upgrade');
    $res->header('Sec-WebSocket-Accept', $key);
    if (isset($req->header['sec-websocket-protocol'])) {
        $res->header('Sec-WebSocket-Protocol', 'vite-hmr');
    }
    $res->status(101);
    $res->end();
    return true;
});
$server->on('message', static function (Swoole\WebSocket\Server $s, Swoole\WebSocket\Frame $f): void {
    if ($f->data === 'bye') {
        $s->disconnect($f->fd);
        return;
    }
    $s->push($f->fd, 'up:' . $f->data);
});
$server->on('request', static function (Swoole\Http\Request $req, Swoole\Http\Response $res): void {
    $uri = $req->server['request_uri'] . (isset($req->server['query_string']) ? '?' . $req->server['query_string'] : '');
    switch ($req->server['request_uri']) {
        case '/gzip':
            $res->header('Content-Type', 'text/plain');
            $res->header('Content-Encoding', 'gzip');
            $res->end(gzencode('compressed by the upstream'));
            return;
        case '/cookies':
            $res->cookie('a', '1');
            $res->cookie('b', '2');
            $res->end('cookies');
            return;
        case '/hang':
            Swoole\Coroutine::sleep(60);
            $res->end('late');
            return;
        case '/fork':
            // A child of its own, as a dev server's worker would be.
            $child = proc_open(['sleep', '300'], [], $pipes);
            $res->end((string) proc_get_status($child)['pid']);
            return;
        case '/via-backend':
            // What a Nitro route handing /api on does: the path asked for, to WINTER_BACKEND.
            $backend = parse_url((string) getenv('WINTER_BACKEND'));
            $client = new Swoole\Coroutine\Http\Client('127.0.0.1', (int) $backend['port']);
            $client->get((string) ($req->get['to'] ?? '/'));
            $res->end(json_encode(['status' => $client->statusCode, 'body' => $client->body]));
            return;
        case '/status':
            $res->status(418);
            $res->end('teapot');
            return;
    }
    $res->header('Content-Type', 'application/json');
    $res->header('X-Upstream', 'yes');
    $res->end(json_encode([
        'method' => $req->server['request_method'],
        'uri' => $uri,
        'host' => $req->header['host'] ?? '',
        'xff' => $req->header['x-forwarded-for'] ?? '',
        'xfproto' => $req->header['x-forwarded-proto'] ?? '',
        'xfhost' => $req->header['x-forwarded-host'] ?? '',
        'connection' => $req->header['connection'] ?? '',
        'type' => $req->header['content-type'] ?? '',
        'length' => strlen((string) $req->rawContent()),
        'files' => array_keys($req->files ?? []),
    ]));
});
$server->start();
