<?php

namespace Flytachi\Winter\Kernel\Process\Socket\Web;

use Flytachi\Winter\Kernel\Process\Socket\Web\PDU\DecodedFrame;
use Flytachi\Winter\Kernel\Process\Socket\Web\PDU\Msg;

/**
 * Handles the encoding and decoding of WebSocket frames according to RFC 6455.
 *
 * This class provides static methods to convert raw data into WebSocket frames
 * and parse incoming frames back into structured messages. It is stateless
 * and can be used without instantiation.
 */
final class WebSocketProtocol
{
    private function __construct()
    {
    }

    /**
     * Performs the WebSocket server-side handshake.
     *
     * Reads the client's HTTP upgrade request from the connection, validates it,
     * and sends the appropriate handshake response if valid.
     *
     * @param resource $connect The client connection resource.
     * @return array|false An array of request info on success, or false on failure.
     */
    public static function handshake($connect): array|false
    {
        $info = [];

        // Read the request line
        $line = @fgets($connect);
        if ($line === false) {
            return false;
        }

        $header = explode(' ', $line);
        if (strtoupper(trim($header[0] ?? '')) !== 'GET') {
            // Only GET method is allowed for handshake
            return false;
        }
        $info['uri'] = $header[1] ?? '/';

        // Parse headers
        while ($line = rtrim(@fgets($connect))) {
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                // Normalize header name to lowercase for easier access
                $info[strtolower($matches[1])] = $matches[2];
            } else {
                break;
            }
        }

        // Validate required headers
        if (
            empty($info['host']) ||
            empty($info['sec-websocket-key']) ||
            empty($info['sec-websocket-version']) || $info['sec-websocket-version'] != 13 ||
            !isset($info['upgrade']) || strtolower($info['upgrade']) !== 'websocket' ||
            !isset($info['connection']) || !str_contains(strtolower($info['connection']), 'upgrade')
        ) {
            // Send a 400 Bad Request response and close
            $response = "HTTP/1.1 400 Bad Request\r\n\r\n";
            @fwrite($connect, $response);
            @fclose($connect);
            return false;
        }

        // Calculate the Sec-WebSocket-Accept key
        $secWebSocketAccept = base64_encode(
            pack('H*', sha1($info['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'))
        );

        // Prepare the handshake response
        $upgradeResponse = "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: {$secWebSocketAccept}\r\n\r\n";

        // Send the response
        if (@fwrite($connect, $upgradeResponse) === false) {
            return false;
        }

        // Add client address to info
        $address = explode(':', stream_socket_get_name($connect, true));
        $info['ip'] = $address[0] ?? null;
        $info['port'] = $address[1] ?? null;
        $urlParts = parse_url($info['uri']);
        $info['path'] = $urlParts['path'] ?? '/';
        parse_str($urlParts['query'] ?? '', $info['params']);

        return $info;
    }

    /**
     * Encodes a payload into a WebSocket frame.
     *
     * @param string $payload The data to encode.
     * @param string $type The type of frame ('text', 'close', 'ping', 'pong').
     * @param bool $masked Should the frame be masked (usually false for server-to-client).
     * @return string The raw WebSocket frame as a binary string.
     * @throws \Exception if the frame is too large.
     */
    public static function encode(
        string $payload,
        string $type = 'text',
        bool $masked = false,
        ?int $closeStatus = null
    ): string {
        if ($type === 'close') {
            $payload = pack('n', $closeStatus ?? 1000) . $payload;
        }

        $frameHead = [];
        $payloadLength = strlen($payload);

        switch ($type) {
            case 'text':
                // FIN + text frame (op-code 1)
                $frameHead[0] = 129; // 1000 0001
                break;
            case 'close':
                // FIN + close frame (op-code 8)
                $frameHead[0] = 136; // 1000 1000
                break;
            case 'ping':
                // FIN + ping frame (op-code 9)
                $frameHead[0] = 137; // 1000 1001
                break;
            case 'pong':
                // FIN + pong frame (op-code 10)
                $frameHead[0] = 138; // 1000 1010
                break;
        }

        // Set mask and payload length
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = ($masked) ? 255 : 127; // 1111 1111 / 0111 1111
            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
            // 8-byte length must not have most significant bit set
            if ($frameHead[2] > 127) {
                throw new \Exception('Frame too large (1004)');
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($masked) ? 254 : 126; // 1111 1110 / 0111 1110
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = ($masked) ? $payloadLength + 128 : $payloadLength;
        }

        // Convert frame-head to string
        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }

        $mask = [];
        if ($masked) {
            // Generate a random mask
            for ($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(rand(0, 255));
            }
            $frameHead = array_merge($frameHead, $mask);
        }
        $frame = implode('', $frameHead);

        // Append payload to frame
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= ($masked) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        return $frame;
    }

    /**
     * Decodes a WebSocket frame from the beginning of a buffer.
     *
     * @param string $buffer The raw binary data from the input buffer.
     * @return DecodedFrame|false A DecodedFrame object on success (containing the message and its length),
     *                            or false if the buffer doesn't contain a complete frame yet.
     */
    public static function decode(string $buffer): DecodedFrame|false
    {
        $bufferLength = strlen($buffer);
        if ($bufferLength < 2) {
            return false; // Not enough data for even the smallest frame header
        }

        $firstByte = ord($buffer[0]);
        $secondByte = ord($buffer[1]);

        $fin = ($firstByte & 128) === 128;
        $opcode = $firstByte & 15;

        $isMasked = ($secondByte & 128) === 128;
        $payloadLength = $secondByte & 127;

        if (!$isMasked) {
            // Per RFC 6455, all frames from client to server MUST be masked.
            $msg = new Msg('error', '', 'Protocol error: Frame not masked (1002)');
            // We return a length of 2 to discard the invalid header.
            return new DecodedFrame($msg, 2);
        }

        $type = '';
        switch ($opcode) {
            case 1:
                $type = 'text';
                break;
            case 2:
                $type = 'binary';
                break;
            case 8:
                $type = 'close';
                break;
            case 9:
                $type = 'ping';
                break;
            case 10:
                $type = 'pong';
                break;
            default:
                $msg = new Msg('error', '', "Unknown opcode: {$opcode} (1003)");
                // We can't know the frame length, so we might have to close the connection.
                // For simplicity, we'll discard 2 bytes.
                return new DecodedFrame($msg, 2);
        }

        $headerOffset = 2;
        if ($payloadLength === 126) {
            if ($bufferLength < 4) {
                return false; // Need 2 more bytes for length
            }
            $payloadLength = unpack('n', substr($buffer, 2, 2))[1];
            $headerOffset = 4;
        } elseif ($payloadLength === 127) {
            if ($bufferLength < 10) {
                return false; // Need 8 more bytes for length
            }
            $parts = unpack('N2', substr($buffer, 2, 8));
            if ($parts[1] > 0 || $parts[2] < 0) { // PHP_INT_MAX check
                $msg = new Msg('error', '', 'Frame too large (1009)');
                // We can't process this frame, so we should discard it.
                // This is tricky, as we don't know the real length.
                // The best course of action is to close the connection.
                // Here, we signal an error and consume the header.
                return new DecodedFrame($msg, 10);
            }
            $payloadLength = $parts[2];
            $headerOffset = 10;
        }

        $maskOffset = $headerOffset;
        $payloadOffset = $maskOffset + 4;
        $frameLength = $payloadOffset + $payloadLength;

        if ($bufferLength < $frameLength) {
            return false;
        }

        $mask = substr($buffer, $maskOffset, 4);
        $payload = '';
        for ($i = 0; $i < $payloadLength; $i++) {
            $payload .= $buffer[$payloadOffset + $i] ^ $mask[$i % 4];
        }

        $msg = new Msg($type, $payload);

        return new DecodedFrame($msg, $frameLength);
    }
}
