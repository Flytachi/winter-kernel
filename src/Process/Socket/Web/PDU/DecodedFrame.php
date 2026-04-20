<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Socket\Web\PDU;

readonly class DecodedFrame
{
    public function __construct(
        public Msg $msg,
        public int $frameLength
    ) {
    }
}
