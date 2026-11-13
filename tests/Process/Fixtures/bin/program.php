<?php

/**
 * An outside program for the supervisor's tests: it writes what happens to it, with the
 * time, so a test can tell what started, became ready and stopped before what.
 *
 *   --name=N          how its lines are marked
 *   --log=FILE        where "<name> <event> <microtime>" lines go
 *   --delay=S         seconds before it is ready
 *   --listen=PORT     ready means: this port accepts connections
 *   --touch=FILE      ready means: this file exists
 *   --stop-delay=S    seconds it takes to stop once asked
 *   --after=S --exit=C  ends by itself after S seconds with exit code C
 *   --close-after=S   stops answering on its port after S seconds, and keeps running
 *   --deaf            says it was asked to stop, and does not stop
 */

declare(strict_types=1);

$o = getopt('', ['name:', 'log:', 'delay:', 'listen:', 'touch:', 'stop-delay:', 'after:', 'exit:', 'close-after:', 'deaf']);
$name = $o['name'] ?? 'program';
$say = static function (string $event) use ($o, $name): void {
    if (isset($o['log'])) {
        file_put_contents($o['log'], sprintf("%s %s %.6f\n", $name, $event, microtime(true)), FILE_APPEND | LOCK_EX);
    }
};

pcntl_async_signals(true);
foreach ([SIGTERM => 'TERM', SIGINT => 'INT'] as $signal => $signalName) {
    pcntl_signal($signal, static function () use ($say, $signalName, $o): void {
        $say("stop-{$signalName}");
        if (isset($o['deaf'])) {
            return;
        }
        usleep((int) ((float) ($o['stop-delay'] ?? 0) * 1_000_000));
        $say('stopped');
        exit(0);
    });
}

$say('start');
usleep((int) ((float) ($o['delay'] ?? 0) * 1_000_000));
$server = null;
if (isset($o['listen'])) {
    $server = stream_socket_server('tcp://127.0.0.1:' . $o['listen']);
    $say('ready');
}
if (isset($o['touch'])) {
    touch($o['touch']);
    $say('ready');
}

$end = isset($o['after']) ? microtime(true) + (float) $o['after'] : INF;
$closeAt = isset($o['close-after']) ? microtime(true) + (float) $o['close-after'] : INF;
while (microtime(true) < $end) {
    if ($server !== null && microtime(true) >= $closeAt) {
        fclose($server);
        $server = null;
        $say('closed');
    }
    if ($server !== null) {
        $connection = @stream_socket_accept($server, 0.1);
        if ($connection !== false) {
            fclose($connection);
        }
    } else {
        usleep(100_000);
    }
}
exit((int) ($o['exit'] ?? 0));
