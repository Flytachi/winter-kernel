<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * Where a frontend is served from and where it answers — what a {@see Frontend} package
 * hands the kernel: the built files, the page that stands for every route a single-page
 * app has (its shell), and its place: a path on the HTTP server's port, or a port of its
 * own with the controller routes that port lets through.
 *
 * A controller always wins: the frontend answers only a GET or HEAD no route took. On its
 * own port the frontend answers everything but its `$routes`, which reach the controllers
 * there — and only there, unless `$exclusive` is false: on the HTTP server's port those
 * prefixes answer 404.
 *
 * @link https://winterframe.net/docs/frontend The frontend in the application
 */
final readonly class FrontSite
{
    private const string NAME = '/^[a-z0-9][a-z0-9_.-]*$/';

    /** The directory served — its trailing slash dropped. */
    public string $root;

    /** Where it answers on its port — `/`, or a path such as `/admin`. */
    public string $path;

    /** @var list<string> The controller prefixes its port lets through, `/admin`. */
    public array $routes;

    /** The folder of content-hashed files, `/_nuxt`; null — none. */
    public ?string $assets;

    /**
     * @param string $name How logs, `app status` and the image name it.
     * @param string $root The built files, an absolute path.
     * @param string|null $shell The page every unknown route gets (a single-page app's
     *     `200.html`); null — pages of their own only (a pre-rendered site, `index.html`).
     * @param string $path Where it answers on its port.
     * @param int|null $port A port of its own; null — the HTTP server's.
     * @param list<string> $routes The controller prefixes its own port lets through.
     * @param bool $exclusive Whether those prefixes are closed on the HTTP server's port.
     * @param bool $critical Whether the application stops when it cannot be served.
     * @param Priority $priority When it starts and stops relative to the other components.
     * @param bool $overlap Whether a controller taking one of its pages is warned of.
     * @param string|null $assets The folder of files whose names carry a hash of their
     *     content (`/_nuxt`): cached for good, and a missing one is a 404, never the shell.
     * @throws \InvalidArgumentException A value it cannot take.
     */
    public function __construct(
        public string $name,
        string $root,
        public ?string $shell = '200.html',
        string $path = '/',
        public ?int $port = null,
        array $routes = [],
        public bool $exclusive = true,
        public bool $critical = false,
        public Priority $priority = Priority::Low,
        public bool $overlap = true,
        ?string $assets = null,
    ) {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new \InvalidArgumentException("name must be lowercase letters, digits, _ . -, \"{$name}\" given.");
        }
        if (!str_starts_with($root, '/') || str_contains($root, "\0")) {
            throw new \InvalidArgumentException("root must be an absolute path, \"{$root}\" given.");
        }
        $this->root = $root === '/' ? '/' : rtrim($root, '/');
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException("path must start with /, \"{$path}\" given.");
        }
        $this->path = $path === '/' ? '/' : rtrim($path, '/');
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException("port must be 1–65535, {$port} given.");
        }
        if ($shell !== null && ($shell === '' || basename($shell) !== $shell || $shell === '..')) {
            throw new \InvalidArgumentException("shell is a file name, \"{$shell}\" given.");
        }
        $prefixes = [];
        foreach ($routes as $route) {
            if (!is_string($route) || !str_starts_with($route, '/')) {
                throw new \InvalidArgumentException('a route prefix starts with /, "' . (string) $route . '" given.');
            }
            $prefixes[] = $route === '/' ? '/' : rtrim($route, '/');
        }
        if ($prefixes !== [] && $port === null) {
            throw new \InvalidArgumentException(
                'routes need a port of its own: on the HTTP server\'s port every route is there.'
            );
        }
        $this->routes = array_values(array_unique($prefixes));
        if ($assets !== null && (!str_starts_with($assets, '/') || $assets === '/' || str_contains($assets, '..'))) {
            throw new \InvalidArgumentException("assets is a folder of the build, as /_nuxt, \"{$assets}\" given.");
        }
        $this->assets = $assets === null ? null : rtrim($assets, '/');
    }

    /** The file whose presence means it is built: its shell, or `index.html`. */
    public function marker(): string
    {
        return $this->root . '/' . ($this->shell ?? 'index.html');
    }

    public function isBuilt(): bool
    {
        return is_file($this->marker());
    }

    /** Whether a controller path is under one of its `$routes`, whole segments only. */
    public function hasRoute(string $path): bool
    {
        foreach ($this->routes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }
}
