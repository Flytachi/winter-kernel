<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\WebSocket;

/**
 * One data message received from a client — what {@see WebSocket::onMessage()} gets.
 *
 * Only text and binary messages arrive here: ping, pong and close are protocol
 * traffic the framework answers itself (a close becomes {@see WebSocket::onClose()}).
 *
 * ```
 * protected function onMessage(Connection $conn, Frame $frame): void
 * {
 *     if ($frame->isBinary) {
 *         return;
 *     }
 *     $msg = json_decode($frame->data, true);
 * }
 * ```
 *
 * @link https://winterframe.net/docs/websockets#frame The Frame object
 */
final readonly class Frame
{
    /**
     * @param string $data The message payload.
     * @param bool $isBinary True for a binary message, false for text.
     */
    public function __construct(
        public string $data,
        public bool $isBinary = false,
    ) {
    }
}
