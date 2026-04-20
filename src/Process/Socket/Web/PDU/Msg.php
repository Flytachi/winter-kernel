<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Socket\Web\PDU;

readonly class Msg
{
    public function __construct(
        public string $type,
        public string $payload,
        public ?string $error = null
    ) {
    }

    public function __toString(): string
    {
        if ($this->error) {
            return "[type:{$this->type}, error:{$this->error}]";
        }
        return "[type:{$this->type}, payload:{$this->payload}]";
    }
}
