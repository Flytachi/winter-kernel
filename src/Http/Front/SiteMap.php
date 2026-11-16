<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Front;

use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\App\FrontSite;

/**
 * Which frontend a request reaches, and which controller routes a port shows.
 *
 * On the HTTP server's port every route is there, but the prefixes a frontend on a port of
 * its own named in its `routes` — unless one of the frontends naming it has `exclusive:
 * false`. On a frontend's own port only its `routes` reach the controllers; the rest is the
 * frontend. A prefix named by several frontends is open on each of their ports.
 *
 * Built once, before the HTTP server starts; asked on every request, with nothing built per
 * request.
 *
 * @internal Between the application, its router and its HTTP server.
 */
final readonly class SiteMap
{
    /** @var list<FrontSite> The sites on the HTTP server's port, the longest path first. */
    private array $onHttp;

    /** @var array<int, FrontSite> The sites on ports of their own, by port. */
    private array $byPort;

    /** @var array<string, bool> Every prefix a site named => whether it is open on the HTTP port. */
    private array $claims;

    /** @var list<string> */
    private array $notes;

    /**
     * @param list<FrontSite> $sites
     * @throws ApplicationConfigException Two sites in one place, or a site on the HTTP server's port.
     */
    public function __construct(array $sites, private int $httpPort)
    {
        $onHttp = [];
        $byPort = [];
        $claims = [];
        $openedBy = [];
        foreach ($sites as $site) {
            if ($site->port === null) {
                foreach ($onHttp as $other) {
                    if ($other->path === $site->path) {
                        throw new ApplicationConfigException(sprintf(
                            '"%s" and "%s" both take %s on port %d: one place serves one frontend — '
                            . 'give one a path of its own, or a port.',
                            $other->name,
                            $site->name,
                            $site->path,
                            $httpPort,
                        ));
                    }
                }
                $onHttp[] = $site;
                continue;
            }
            if ($site->port === $httpPort) {
                throw new ApplicationConfigException(sprintf(
                    '"%s" asks for port %d: port %d is the HTTP server\'s — leave out port: to serve it there.',
                    $site->name,
                    $site->port,
                    $httpPort,
                ));
            }
            if (isset($byPort[$site->port])) {
                throw new ApplicationConfigException(sprintf(
                    '"%s" and "%s" both take port %d: one port serves one frontend.',
                    $byPort[$site->port]->name,
                    $site->name,
                    $site->port,
                ));
            }
            $byPort[$site->port] = $site;
            foreach ($site->routes as $prefix) {
                $claims[$prefix] = ($claims[$prefix] ?? false) || !$site->exclusive;
                if (!$site->exclusive) {
                    $openedBy[$prefix] ??= $site->name;
                }
            }
        }
        usort($onHttp, static fn(FrontSite $a, FrontSite $b): int => strlen($b->path) <=> strlen($a->path));
        ksort($byPort);

        $notes = [];
        foreach ($openedBy as $prefix => $name) {
            $notes[] = "{$prefix} is open on the HTTP port: \"{$name}\" has exclusive: false";
        }

        $this->onHttp = $onHttp;
        $this->byPort = $byPort;
        $this->claims = $claims;
        $this->notes = $notes;
    }

    public function isEmpty(): bool
    {
        return $this->onHttp === [] && $this->byPort === [];
    }

    /** The site a request on this port and path reaches; null — none. */
    public function siteFor(int $port, string $path): ?FrontSite
    {
        if ($port !== $this->httpPort) {
            $site = $this->byPort[$port] ?? null;
            return $site !== null && self::under($path, $site->path) ? $site : null;
        }
        foreach ($this->onHttp as $site) {
            if (self::under($path, $site->path)) {
                return $site;
            }
        }
        return null;
    }

    /** Whether a controller route on this path is reachable on this port. */
    public function routeVisible(int $port, string $path): bool
    {
        if ($port !== $this->httpPort) {
            return isset($this->byPort[$port]) && $this->byPort[$port]->hasRoute($path);
        }
        foreach ($this->claims as $prefix => $openOnHttp) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $openOnHttp;
            }
        }
        return true;
    }

    /**
     * Whether the path is one of the controller prefixes a frontend's own port lets
     * through — the controllers' territory there: what no route took is their 404, never
     * the frontend's page.
     */
    public function isRouteOf(int $port, string $path): bool
    {
        return $port !== $this->httpPort && isset($this->byPort[$port]) && $this->byPort[$port]->hasRoute($path);
    }

    /** @return list<int> The ports of their own the HTTP server listens on as well. */
    public function ports(): array
    {
        return array_keys($this->byPort);
    }

    /** @return list<FrontSite> */
    public function sites(): array
    {
        return [...$this->onHttp, ...array_values($this->byPort)];
    }

    /** @return list<string> What the application should be told at start. */
    public function notes(): array
    {
        return $this->notes;
    }

    private static function under(string $path, string $base): bool
    {
        return $base === '/' || $path === $base || str_starts_with($path, $base . '/');
    }
}
