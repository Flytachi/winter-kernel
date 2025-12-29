<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Socket\Web;

use Flytachi\Winter\Kernel\Process\Core\Dispatch;
use Flytachi\Winter\Kernel\Process\Socket\Web\PDU\Msg;
use Flytachi\Winter\Kernel\Process\Socket\Web\PDU\Resource;
use Flytachi\Winter\Kernel\Process\Traits\ThreadSignalHandler;

abstract class ThreadWebSocket extends Dispatch
{
    use SocketWebServerHandler;
    use ThreadSignalHandler;

    /** @var \resource */
    protected $resourceConnection;

    /** @var \resource[] */
    protected array $resourceConnects = [];

    /** @var Resource */
    protected Resource $connection;

    /** @var Resource[] */
    protected array $connects = [];

    protected string $exNamespace = 'web-socket';


    protected function handle(Resource &$resource, Msg $msg): void
    {
        $this->logger->notice("handle: {$resource} => Send {$msg}");
    }

    protected function handleConnect(Resource &$resource): void
    {
        $this->logger->notice("handleConnect: {$resource} => New connection accepted");
    }

    protected function handleDisconnect(Resource &$resource): void
    {
        $this->logger->notice("handleDisconnect: {$resource} => Connection closing");
    }

    final protected function resolutionStart(): void
    {
        parent::resolutionStart();
        $this->prepareSignalHandler();
    }

    final protected function resolutionEnd(): void
    {
        $this->socketClose();
    }

    final protected function socketStart(int $rps = 2, int $timeWorkLimit = 0): void
    {
        $this->timeWorkLimit = $timeWorkLimit;
        $this->logger->info("Starting the Web Server...");
        $this->logger->info("Stream: tcp://{$this->ip}:{$this->port}");

        try {
            $this->resourceConnection = stream_socket_server(
                'tcp://' . $this->ip . ':' . $this->port,
                $errno,
                $errorStr
            );
            if (!$this->resourceConnection) {
                throw new ThreadException("Cannot start server: {$errorStr}({$errno})");
            }
            $this->connection = new Resource($this->resourceConnection);

            $this->logger->debug("Server is running...");
            $this->startTime = time();
            $this->listen($rps);
        } catch (\Throwable $exception) {
            $this->logger->critical($exception->getMessage());
        }
    }

    final protected function closeConnect(Resource $resource): void
    {
        fwrite($resource->getConnect(), self::encode('  Closed on client demand', 'close'));
        fclose($resource->getConnect());
        unset($this->resourceConnects[(string) $resource]);
        unset($this->connects[(string) $resource]);
    }

    final protected function socketClose(): void
    {
        fclose($this->resourceConnection);
        if (!empty($this->resourceConnects)) {
            foreach ($this->resourceConnects as $connect) {
                if (is_resource($connect)) {
                    fwrite($connect, self::encode('  Closed on server demand', 'close'));
                    fclose($connect);
                    unset($this->resourceConnects[(string) $connect]);
                    unset($this->connects[(string) $connect]);
                }
            }
        }
    }

    private function listen(int $rps): void
    {
        while (true) {
            $read = $this->resourceConnects;
            $read[] = $this->resourceConnection;
            $write = $except = null;
            if (
                !@stream_select(
                    $read,
                    $write,
                    $except,
                    0,
                    ($rps < 1000 ? 1_000_000 / $rps : 1000)
                )
            ) {
                continue;
            }

            // new connection
            if (in_array($this->resourceConnection, $read)) {
                if (
                    ($connect = stream_socket_accept($this->resourceConnection, -1))
                    && ($info = $this->handshake($connect))
                ) {
                    $this->resourceConnects[(string) $connect] = $connect;
                    $this->connects[(string) $connect] = new Resource($connect, $info);
                    try {
                        $this->handleConnect($this->connects[(string) $connect]);
                    } catch (\Throwable $exception) {
                        $this->logger->critical('handlerConnect: ' . $exception->getMessage());
                    }
                }
                unset($read[array_search($this->resourceConnection, $read)]);
            }

            // new message
            foreach ($read as $connect) {
                $data = fread($connect, 100000);
                $decoded = self::decode($data);

                if (false === $decoded || 'close' === $decoded->type) {
                    try {
                        $this->handleDisconnect($this->connects[(string) $connect]);
                    } catch (\Throwable $exception) {
                        $this->logger->critical('handlerDisconnect: ' . $exception->getMessage());
                    }
                    try {
                        fwrite($connect, self::encode('  Closed on client demand', 'close'));
                        fclose($connect);
                    } catch (\Throwable $exception) {
                        $this->logger->error('handlerDisconnect: ' . $exception->getMessage());
                    }
                    unset($this->resourceConnects[(string) $connect]);
                    unset($this->connects[(string) $connect]);
                    continue;
                }

                try {
                    $this->handle($this->connects[(string) $connect], $decoded);
                } catch (\Throwable $exception) {
                    $this->logger->critical('handler: ' . $exception->getMessage());
                }
            }

            // close by time work limit
            if ($this->timeWorkLimit && time() - $this->startTime > $this->timeWorkLimit) {
                $this->logger->debug('Time limit. Stopping server');
                $this->socketClose();
            }
            pcntl_signal_dispatch();
        }
    }

    /**
     * Encoding messages before sending to the client
     * @param $payload
     * @param string $type
     * @param bool $masked
     * @return array|string
     */
    private static function encode($payload, string $type = 'text', bool $masked = false): array|string
    {
        $frameHead = array();
        $payloadLength = strlen($payload);

        switch ($type) {
            case 'text':
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;
            case 'close':
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;
            case 'ping':
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;
            case 'pong':
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }

        // set mask and payload length (using 1, 3 or 9 bytes)
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 255 : 127;
            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
            // most significant bit MUST be 0
            if ($frameHead[2] > 127) {
                return array('type' => '', 'payload' => '', 'error' => 'frame too large (1004)');
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
        }

        // convert frame-head to string:
        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }
        if ($masked === true) {
            // generate a random mask:
            $mask = [];
            for ($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(rand(0, 255));
            }
            $frameHead = array_merge($frameHead, $mask);
        }
        $frame = implode('', $frameHead);

        // append payload to frame:
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        return $frame;
    }

    /**
     * Decoding messages received from the client
     * @param $msgEnc
     * @return Msg|false
     */
    private static function decode($msgEnc): Msg|false
    {
        if (!strlen($msgEnc)) {
            return false;
        }

        $unmaskedPayload = '';
        $decodedData = [];

        // estimate frame type:
        $firstByteBinary = sprintf('%08b', ord($msgEnc[0]));
        $secondByteBinary = sprintf('%08b', ord($msgEnc[1]));
        $opcode = bindec(substr($firstByteBinary, 4, 4));
        $isMasked = $secondByteBinary[0] == '1';
        $payloadLength = ord($msgEnc[1]) & 127;

        // unmasked frame is received:
        if (!$isMasked) {
            return new Msg('', '', 'protocol opcode (1002)');
        }

        switch ($opcode) {
            // text frame:
            case 1:
                $decodedData['type'] = 'text';
                break;
            case 2:
                $decodedData['type'] = 'binary';
                break;
            // connection close frame:
            case 8:
                $decodedData['type'] = 'close';
                break;
            // ping frame:
            case 9:
                $decodedData['type'] = 'ping';
                break;
            // pong frame:
            case 10:
                $decodedData['type'] = 'pong';
                break;
            default:
                return new Msg('', '', 'unknown opcode (1003)');
        }

        if ($payloadLength === 126) {
            $mask = substr($msgEnc, 4, 4);
            $payloadOffset = 8;
            $dataLength = bindec(sprintf('%08b', ord($msgEnc[2])) . sprintf('%08b', ord($msgEnc[3]))) + $payloadOffset;
        } elseif ($payloadLength === 127) {
            $mask = substr($msgEnc, 10, 4);
            $payloadOffset = 14;
            $tmp = '';
            for ($i = 0; $i < 8; $i++) {
                $tmp .= sprintf('%08b', ord($msgEnc[$i + 2]));
            }
            $dataLength = bindec($tmp) + $payloadOffset;
            unset($tmp);
        } else {
            $mask = substr($msgEnc, 2, 4);
            $payloadOffset = 6;
            $dataLength = $payloadLength + $payloadOffset;
        }

        /**
         * We have to check for large frames here. socket_recv cuts at 1024 bytes
         * so if websocket-frame is > 1024 bytes we have to wait until whole
         * data is transfer.
         */
        if (strlen($msgEnc) < $dataLength) {
            return false;
        }

        if ($isMasked) {
            for ($i = $payloadOffset; $i < $dataLength; $i++) {
                $j = $i - $payloadOffset;
                if (isset($msgEnc[$i])) {
                    $unmaskedPayload .= $msgEnc[$i] ^ $mask[$j % 4];
                }
            }
            $decodedData['payload'] = $unmaskedPayload;
        } else {
            $payloadOffset = $payloadOffset - 4;
            $decodedData['payload'] = substr($msgEnc, $payloadOffset);
        }

        return new Msg($decodedData['type'], $decodedData['payload']);
    }

    /**
     * "Handshake", i.e. sending headers according to the WebSocket protocol
     * @param $connect
     * @return false|array
     */
    private function handshake($connect): false|array
    {
        $info = array();

        $line = fgets($connect);
        $header = explode(' ', $line);
        $info['method'] = $header[0] ?? null;
        $info['uri'] = $header[1] ?? null;
        $data = parseUrlDetail($info['uri']);
        $info['url'] = $data['path'];
        $info['params'] = $data['query'];

        while ($line = rtrim(fgets($connect))) {
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $info[$matches[1]] = $matches[2];
            } else {
                break;
            }
        }

        // получаем адрес клиента
        $address = explode(':', stream_socket_get_name($connect, true));
        $info['ip'] = $address[0];
        $info['port'] = $address[1];

        if (empty($info['Sec-WebSocket-Key'])) {
            return false;
        }

        $SecWebSocketAccept =
            base64_encode(pack('H*', sha1($info['Sec-WebSocket-Key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept:" . $SecWebSocketAccept . "\r\n\r\n";
        fwrite($connect, $upgrade);

        return $info;
    }
}
