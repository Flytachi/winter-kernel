<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route;

use Flytachi\Winter\Base\DebugDump;
use Flytachi\Winter\Base\ReflectionCache;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\Header;
use Flytachi\Winter\K2\Http\ParameterResolver;
use Flytachi\Winter\K2\Localization\Locale;
use Flytachi\Winter\K2\Http\Response\ExceptionWrapper;
use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Http\Response\ResponseException;
use Flytachi\Winter\K2\Http\Response\Sendable;
use Flytachi\Winter\K2\Stereotype\Middleware;
use Flytachi\Winter\Base\HttpCode;

/**
 * K2 Router — dual-mode (Swoole + FPM), Spring Boot-style.
 *
 * Route registration:
 *   $router->get('/users',          [UserController::class, 'index']);
 *   $router->get('/users/{id:\d+}', [UserController::class, 'show']);
 *
 * Attribute-based (preferred):
 *   $router = Router::fromScan(__DIR__ . '/src');
 *
 *   // Dispatch a request (works identically in Swoole and FPM):
 *   $router->handle(new SwooleRequest($req), new SwooleResponse($res));
 *   $router->handle(new FpmRequest(),        new FpmResponse());
 */
class Router
{
    /** @var array<string, array<string, mixed>> [METHOD][path] => handler */
    private array $staticRoutes  = [];

    /** @var list<Route> */
    private array $dynamicRoutes = [];

    private ?Dispatcher $dispatcher = null;

    // ── Route registration ────────────────────────────────────────────────────

    /**
     * @param list<array{class: class-string<Middleware>, args: array}> $middlewares
     */
    public function add(string $method, string $path, mixed $handler, array $middlewares = []): static
    {
        $this->dispatcher = null;

        $method = strtoupper($method);

        $stored = $middlewares !== []
            ? ['__handler' => $handler, '__middlewares' => $middlewares]
            : $handler;

        $route = new Route($method, $path, $stored);

        if ($route->isStatic()) {
            $this->staticRoutes[$method][$path] = $stored;
        } else {
            $this->dynamicRoutes[] = $route;
        }

        return $this;
    }

    public function get(string $path, mixed $handler): static    { return $this->add('GET',    $path, $handler); }
    public function post(string $path, mixed $handler): static   { return $this->add('POST',   $path, $handler); }
    public function put(string $path, mixed $handler): static    { return $this->add('PUT',    $path, $handler); }
    public function patch(string $path, mixed $handler): static  { return $this->add('PATCH',  $path, $handler); }
    public function delete(string $path, mixed $handler): static { return $this->add('DELETE', $path, $handler); }
    public function options(string $path, mixed $handler): static{ return $this->add('OPTIONS',$path, $handler); }

    // ── Factory methods ───────────────────────────────────────────────────────

    /**
     * Scan $rootDir for #[GetMapping] / #[PostMapping] / … controllers,
     * register routes, and configure ExceptionWrapper for custom exception handlers.
     *
     * @param string[] $exclude  Directories to skip
     */
    public static function fromScan(string $rootDir, array $exclude = []): static
    {
        $router = new static();
        MappingScanner::scan($rootDir, $router, $exclude);
        ExceptionWrapper::configure($rootDir);
        return $router;
    }

    /** Add attribute-scanned routes from $rootDir to this Router instance. */
    public function scan(string $rootDir, array $exclude = []): static
    {
        MappingScanner::scan($rootDir, $this, $exclude);
        return $this;
    }

    /**
     * Register routes from all currently declared classes implementing ControllerInterface.
     * Use this when controllers are defined inline (e.g. in tests).
     */
    public static function fromDeclared(): static
    {
        $router = new static();
        MappingScanner::scanDeclared($router);
        return $router;
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    public function dispatch(string $method, string $uri): RouteResult
    {
        if ($this->dispatcher === null) {
            $this->dispatcher = new Dispatcher($this->staticRoutes, $this->dynamicRoutes);
        }

        $path = ($pos = strpos($uri, '?')) !== false ? substr($uri, 0, $pos) : $uri;

        return $this->dispatcher->dispatch($method, $path);
    }

    /**
     * Handle an HTTP request — works in both Swoole and FPM modes.
     *
     * Swoole:
     *   $router->handle(new SwooleRequest($req), new SwooleResponse($res));
     *
     * FPM:
     *   $router->handle(new FpmRequest(), new FpmResponse());
     */
    public function handle(HttpRequest $request, HttpResponse $response): void
    {
        Header::init($request);
        Locale::initFromRequest();

        try {
            $result = $this->dispatch($request->getMethod(), $request->getUri());

            match ($result->status) {
                RouteResult::FOUND => $this->invoke($result->handler, $request, $response, $result->params),

                RouteResult::METHOD_NOT_ALLOWED => throw (new ResponseException(
                    'Method Not Allowed', HttpCode::METHOD_NOT_ALLOWED
                ))->withHeader('Allow', implode(', ', $result->allowedMethods)),

                default => throw new ResponseException('Not Found', HttpCode::NOT_FOUND),
            };
        } catch (\Throwable $e) {
            $this->sendError($e, $response);
        }
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function invoke(mixed $stored, HttpRequest $req, HttpResponse $res, array $params): void
    {
        try {
            // ── Unpack middleware wrapper ─────────────────────────────────────
            $middlewareDefs = [];
            if (is_array($stored) && array_key_exists('__handler', $stored)) {
                $middlewareDefs = $stored['__middlewares'];
                $handler        = $stored['__handler'];
            } else {
                $handler = $stored;
            }

            // ── Run before() on each middleware ───────────────────────────────
            /** @var Middleware[] $stack */
            $stack = [];
            foreach ($middlewareDefs as $def) {
                /** @var Middleware $mw */
                $mw = new $def['class'](...$def['args']);
                $mw->before($req, $res);
                $stack[] = $mw;
            }

            // ── Resolve handler and invoke ────────────────────────────────────
            if (is_array($handler) && is_string($handler[0])) {
                [$class, $methodName] = $handler;
                $object    = ReflectionCache::controller($class);
                $refMethod = ReflectionCache::method($class, $methodName);
                $args      = ParameterResolver::resolve($refMethod, $req, $res, $params);
                $result    = $refMethod->invokeArgs($object, $args);
            } else {
                $result = ($handler)($req, $res, $params);
            }

            // ── Run after() in reverse order ──────────────────────────────────
            foreach (array_reverse($stack) as $mw) {
                $result = $mw->after($result);
            }

            // ── Serialize return value ────────────────────────────────────────
            if ($result instanceof Sendable) {
                $result->send($res);
            } elseif ($result !== null) {
                ResponseEntity::ok($result)->send($res);
            }

        } catch (\Throwable $e) {
            $this->sendError($e, $res);
        }
    }

    private function sendError(\Throwable $e, HttpResponse $res): void
    {
        // dd() — перехватываем до ExceptionWrapper, рендерим без die()
        if ($e instanceof \Flytachi\Winter\Base\Exception\DebugDumpException) {
            $res->status(200);
            $res->header('Content-Type', 'text/html; charset=utf-8');
            $res->end($this->renderDump($e));
            return;
        }

        $exc  = ExceptionWrapper::wrap($e);
        $body = $exc->getBody(); // must be first — sets Content-Type via addHeader()
        $res->status($exc->getHttpCode()->value);
        foreach ($exc->getHeader() as $key => $value) {
            $res->header($key, $value);
        }
        $res->end($body);
    }

    private function renderDump(\Flytachi\Winter\Base\Exception\DebugDumpException $e): string
    {
        $info   = $e->getInfo();
        $values = $e->getValues();

        $rows = '';
        $count = count($values);
        foreach ($values as $i => $value) {
            $rendered = match (gettype($value)) {
                'NULL'              => '<span style="color:#999">null</span>',
                'boolean'           => '<span style="color:#00ff00">' . var_export($value, true) . '</span>',
                'integer', 'double' => '<span style="color:#00ffff">' . var_export($value, true) . '</span>',
                'object'            => '<span style="color:#ff7033">' . htmlspecialchars(print_r($value, true), ENT_QUOTES) . '</span>',
                'array'             => '<span style="color:#cb71ff">' . htmlspecialchars(print_r($value, true), ENT_QUOTES) . '</span>',
                'string'            => '<span style="color:#e4ff6c">' . htmlspecialchars(var_export($value, true), ENT_QUOTES) . '</span>',
                default             => '<span style="color:#fa5151">' . htmlspecialchars(var_export($value, true), ENT_QUOTES) . '</span>',
            };
            $rows .= $rendered;
            if ($i < $count - 1) {
                $rows .= '<hr style="border:1px dashed #444">';
            }
        }

        $file   = htmlspecialchars($info['file'] ?? '', ENT_QUOTES);
        $line   = htmlspecialchars((string) ($info['line'] ?? ''), ENT_QUOTES);
        $memory = htmlspecialchars($info['memory'] ?? '', ENT_QUOTES);
        $delta  = htmlspecialchars((string) ($info['delta'] ?? ''), ENT_QUOTES);
        $time   = htmlspecialchars($info['time'] ?? '', ENT_QUOTES);
        $tz     = htmlspecialchars($info['timezone'] ?? '', ENT_QUOTES);

        return <<<HTML
            <body style="background-color:#0a0f1f">
            <div style="border:2px solid #3e006f;border-radius:7px;padding:10px;background-color:black">
                <div style="display:flex;justify-content:space-between;margin:8px 0 17px">
                    <span style="font-size:1.2rem;color:#fff">
                        <span style="color:#7f00e0;font-weight:bold">DUMP and DIE:</span>
                        {$file} ({$line})
                    </span>
                    <span style="font-style:italic">
                        <span style="color:#adadad">{$time}</span>
                        <span style="color:#00ffff">{$tz}</span>
                    </span>
                </div>
                <hr style="border:1px solid #999">
                <pre style="margin:10px;white-space:pre-wrap;word-wrap:break-word">{$rows}</pre>
                <hr style="border:1px solid #999">
                <div style="display:flex;justify-content:space-between">
                    <span style="color:#9e9e9e;font-weight:bold">Memory {$memory}</span>
                    <span style="color:#9e9e9e;font-style:italic">Time {$delta}</span>
                </div>
            </div>
            </body>
            HTML;
    }
}
