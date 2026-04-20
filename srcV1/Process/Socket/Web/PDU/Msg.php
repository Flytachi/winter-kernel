<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Socket\Web\PDU;

use JetBrains\PhpStorm\Deprecated;

/**
 * A Data Transfer Object representing a WebSocket message.
 * This object is immutable.
 */
#[Deprecated]
readonly class Msg
{
    public string $type;
    public string $payload;
    public ?string $error;

    /**
     * @param string $type The message type (e.g., 'text', 'binary', 'close', 'error').
     * @param string $payload The message payload.
     * @param string|null $error An optional error message.
     */
    public function __construct(string $type, string $payload, ?string $error = null)
    {
        $this->type = $type;
        $this->payload = $payload;
        $this->error = $error;
    }

    public function __toString(): string
    {
        if ($this->error) {
            return "[type:{$this->type}, error:{$this->error}]";
        }
        return "[type:{$this->type}, payload:{$this->payload}]";
    }
}
