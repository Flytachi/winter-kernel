<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Old\Process\Socket\Web;

use Flytachi\Winter\K2\Old\Process\Socket\Web\PDU\DecodedFrame;
use Flytachi\Winter\K2\Old\Process\Socket\Web\PDU\Msg;

final class WebSocketProtocol
{
    private function __construct()
    {
    }

    /** @param resource $connect */
    public static function handshake($connect): array|false
    {
        $info = [];

        $line = @fgets($connect);
        if ($line === false) {
            return false;
        }

        $header = explode(' ', $line);
        if (strtoupper(trim($header[0] ?? '')) !== 'GET') {
            return false;
        }
        $info['uri'] = $header[1] ?? '/';

        while ($line = rtrim(@fgets($connect))) {
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $info[strtolower($matches[1])] = $matches[2];
            } else {
                break;
            }
        }

        if (
            empty($info['host']) ||
            empty($info['sec-websocket-key']) ||
            empty($info['sec-websocket-version']) || $info['sec-websocket-version'] != 13 ||
            !isset($info['upgrade']) || strtolower($info['upgrade']) !== 'websocket' ||
            !isset($info['connection']) || !str_contains(strtolower($info['connection']), 'upgrade')
        ) {
            @fwrite($connect, "HTTP/1.1 400 Bad Request\r\n\r\n");
            @fclose($connect);
            return false;
        }

        $accept = base64_encode(
            pack('H*', sha1($info['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'))
        );

        if (
            @fwrite($connect, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\n"
                . "Connection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n") === false
        ) {
            return false;
        }

        $address       = explode(':', stream_socket_get_name($connect, true));
        $info['ip']    = $address[0] ?? null;
        $info['port']  = $address[1] ?? null;
        $urlParts      = parse_url($info['uri']);
        $info['path']  = $urlParts['path'] ?? '/';
        parse_str($urlParts['query'] ?? '', $info['params']);

        return $info;
    }

    public static function encode(
        string $payload,
        string $type = 'text',
        bool $masked = false,
        ?int $closeStatus = null
    ): string {
        if ($type === 'close') {
            $payload = pack('n', $closeStatus ?? 1000) . $payload;
        }

        $frameHead     = [];
        $payloadLength = strlen($payload);

        $frameHead[0] = match ($type) {
            'close' => 136,
            'ping'  => 137,
            'pong'  => 138,
            default => 129, // text
        };

        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = $masked ? 255 : 127;
            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
            if ($frameHead[2] > 127) {
                throw new \Exception('Frame too large (1004)');
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = $masked ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = $masked ? $payloadLength + 128 : $payloadLength;
        }

        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }

        $mask = [];
        if ($masked) {
            for ($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(rand(0, 255));
            }
            $frameHead = array_merge($frameHead, $mask);
        }
        $frame = implode('', $frameHead);

        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= $masked ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        return $frame;
    }

    public static function decode(string $buffer): DecodedFrame|false
    {
        $bufferLength = strlen($buffer);
        if ($bufferLength < 2) {
            return false;
        }

        $firstByte  = ord($buffer[0]);
        $secondByte = ord($buffer[1]);
        $opcode     = $firstByte & 15;
        $isMasked   = ($secondByte & 128) === 128;
        $payloadLength = $secondByte & 127;

        if (!$isMasked) {
            return new DecodedFrame(new Msg('error', '', 'Protocol error: Frame not masked (1002)'), 2);
        }

        $type = match ($opcode) {
            1  => 'text',
            2  => 'binary',
            8  => 'close',
            9  => 'ping',
            10 => 'pong',
            default => null,
        };

        if ($type === null) {
            return new DecodedFrame(new Msg('error', '', "Unknown opcode: {$opcode} (1003)"), 2);
        }

        $headerOffset = 2;
        if ($payloadLength === 126) {
            if ($bufferLength < 4) {
                return false;
            }
            $payloadLength = unpack('n', substr($buffer, 2, 2))[1];
            $headerOffset  = 4;
        } elseif ($payloadLength === 127) {
            if ($bufferLength < 10) {
                return false;
            }
            $parts = unpack('N2', substr($buffer, 2, 8));
            if ($parts[1] > 0 || $parts[2] < 0) {
                return new DecodedFrame(new Msg('error', '', 'Frame too large (1009)'), 10);
            }
            $payloadLength = $parts[2];
            $headerOffset  = 10;
        }

        $payloadOffset = $headerOffset + 4;
        $frameLength   = $payloadOffset + $payloadLength;

        if ($bufferLength < $frameLength) {
            return false;
        }

        $mask    = substr($buffer, $headerOffset, 4);
        $payload = '';
        for ($i = 0; $i < $payloadLength; $i++) {
            $payload .= $buffer[$payloadOffset + $i] ^ $mask[$i % 4];
        }

        return new DecodedFrame(new Msg($type, $payload), $frameLength);
    }
}
