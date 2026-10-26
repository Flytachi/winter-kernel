<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

use Flytachi\Winter\Kernel\App\Config\ServerSettings;
use Flytachi\Winter\Kernel\Route\DevWatcher;
use Flytachi\Winter\Kernel\Route\Router;

/**
 * What the HTTP server's process starts from, prepared once in the application's process:
 * the resolved server settings, the scanned routes, the dev watcher under `call run dev`.
 *
 * @internal Between the application and its HTTP component.
 */
final readonly class HttpTier
{
    public function __construct(
        public ServerSettings $settings,
        public Router $router,
        public ?DevWatcher $dev,
    ) {
    }

    public function host(): string
    {
        return $this->settings->getHost();
    }

    public function port(): int
    {
        return $this->settings->getPort();
    }
}
