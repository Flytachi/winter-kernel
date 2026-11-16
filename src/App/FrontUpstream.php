<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * Where a frontend's own server listens, as the kernel set it up ({@see FrontProgram}):
 * what the HTTP server forwards the frontend's requests to.
 *
 * @internal Made by the kernel, read by its router.
 */
final readonly class FrontUpstream
{
    /**
     * @param int $port Its loopback port.
     * @param bool $server Whether every method no controller took goes to it.
     * @param bool $dev A development server: the built files are never sent in its place,
     *     and it is asked as `localhost` — a development server refuses names it does not know.
     */
    public function __construct(
        public int $port,
        public bool $server,
        public bool $dev,
    ) {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("port must be 1–65535, {$port} given.");
        }
    }
}
