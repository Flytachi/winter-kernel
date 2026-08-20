<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Config;

use Flytachi\Winter\Kernel\App\ApplicationArguments;

/**
 * Bind address and Swoole tuning — the one web-tier concern that belongs to the
 * application alone.
 *
 * It is separate from {@see WebConfigurer} because the two compose differently, and the
 * difference is arity rather than subject. CORS is a set of rules: two contributors add
 * up, and the result is the union. Server settings are one object mutated in place: two
 * contributors do not add up, the later one simply overwrites the earlier, and which is
 * "later" would come down to the order the scanner happened to walk the filesystem.
 *
 * So an imported package may contribute CORS rules and controllers, and may not decide
 * where the server binds or how many workers it runs. A `ServerConfigurer` found outside
 * the project root is refused at boot by name, rather than silently overruling the
 * application that owns the process.
 *
 * Exactly one implementation is allowed. Leave the handle untouched and the framework
 * default stands: `--host`/`--port`, falling back to `0.0.0.0:8000`.
 *
 * @link https://winterframe.net/docs/web-configuration The web-layer configuration contracts
 */
interface ServerConfigurer
{
    /**
     * @param ServerSettings $server Pre-seeded with the framework default; tune in place.
     * @param ApplicationArguments $args Parsed CLI arguments, so the bind address can come
     *                                   from a flag, the environment, or a literal.
     */
    public function configureServer(ServerSettings $server, ApplicationArguments $args): void;
}
