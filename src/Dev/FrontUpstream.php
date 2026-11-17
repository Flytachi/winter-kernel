<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Dev;

/**
 * Where a frontend's development server listens under `call run dev`, as the kernel set it
 * up ({@see FrontProgram}): what the HTTP server forwards the frontend's pages, files and
 * live-reload socket to.
 *
 * @internal Made by the kernel, read by its router.
 */
final readonly class FrontUpstream
{
    /** @param int $port Its loopback port. */
    public function __construct(public int $port)
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("port must be 1–65535, {$port} given.");
        }
    }
}
