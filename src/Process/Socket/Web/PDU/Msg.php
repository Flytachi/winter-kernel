<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Socket\Web\PDU;

readonly class Msg
{
    public string $type;
    public string $payload;
    public ?string $error;

    /**
     * @param string $type
     * @param string $payload
     * @param string|null $error
     */
    public function __construct(string $type, string $payload, ?string $error = null)
    {
        $this->type = $type;
        $this->payload = $payload;
        $this->error = $error;
    }

    public function __toString(): string
    {
        return "[{$this->type}:{$this->payload}]";
    }
}
