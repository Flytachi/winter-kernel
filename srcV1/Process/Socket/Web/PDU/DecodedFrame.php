<?php

namespace Flytachi\Winter\Kernel\Process\Socket\Web\PDU;

use JetBrains\PhpStorm\Deprecated;

/**
 * A Data Transfer Object returned by WebSocketProtocol::decode.
 * It contains the decoded message and the total length of the frame that was processed.
 */
#[Deprecated]
readonly class DecodedFrame
{
    /**
     * @param Msg $msg The decoded WebSocket message.
     * @param int $frameLength The total number of bytes the frame occupied in the buffer.
     */
    public function __construct(
        public Msg $msg,
        public int $frameLength
    ) {
    }
}
