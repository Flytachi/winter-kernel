<?php

/*
 * A browser's WebSocket for the tests, in a process of its own — a coroutine run in the
 * test's process would leave Swoole's loop behind for the tests that fork after it.
 * Args: port, path, then the words to say ("bye" closes from the program's side).
 * Prints JSON: upgraded, status, protocol, said.
 */

declare(strict_types=1);

[, $port, $path] = $argv;
$words = array_slice($argv, 3);
// `--mark` first: the socket carries the mark of a request already forwarded.
$mark = ($words[0] ?? '') === '--mark';
if ($mark) {
    array_shift($words);
}
$result = ['upgraded' => false, 'status' => 0, 'protocol' => null, 'said' => []];
Swoole\Coroutine\run(static function () use ($port, $path, $words, $mark, &$result): void {
    $client = new Swoole\Coroutine\Http\Client('127.0.0.1', (int) $port);
    $client->set(['timeout' => 5]);
    $client->setHeaders(['Host' => "127.0.0.1:{$port}", 'Sec-WebSocket-Protocol' => 'vite-hmr']
        + ($mark ? ['X-Winter-Forwarded' => '1'] : []));
    $result['upgraded'] = $client->upgrade($path);
    $result['status'] = (int) $client->statusCode;
    $result['protocol'] = $client->headers['sec-websocket-protocol'] ?? null;
    if ($result['upgraded']) {
        foreach ($words as $word) {
            $client->push($word);
            $frame = $client->recv(3);
            $closed = $frame === false || $frame === ''
                || ($frame instanceof Swoole\WebSocket\Frame && $frame->opcode === WEBSOCKET_OPCODE_CLOSE);
            $result['said'][] = $closed ? 'closed' : $frame->data;
        }
    }
    $client->close();
});
echo json_encode($result);
