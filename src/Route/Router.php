<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Route;

use Flytachi\Winter\Base\Exception\DebugDumpException;
use Flytachi\Winter\Base\Log\LoggerRegistry;
use Flytachi\Winter\Base\ReflectionCache;
use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\K2\Exception\LogLevelException;
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
 * Global CORS:
 *   $router->cors(origins: ['https://app.example.com'], credentials: true, maxAge: 3600);
 *
 * Dispatch a request (works identically in Swoole and FPM):
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

    /**
     * Global CORS configuration — applied to every route unless overridden
     * by a per-route #[CrossOrigin] attribute.
     *
     * @var array{
     *   origins: string[],
     *   allowHeaders: string[],
     *   exposeHeaders: string[],
     *   credentials: bool,
     *   maxAge: int,
     *   vary: string[],
     * }|null
     */
    private ?array $globalCors = null;

    // ── CORS configuration ────────────────────────────────────────────────────

    /**
     * Enable CORS globally for all routes.
     *
     * Per-route #[CrossOrigin] will override this config for that specific route.
     *
     * @param string[] $origins        Allowed origins. Empty = '*'.
     * @param string[] $allowHeaders   Allowed request headers. Empty = reflect Access-Control-Request-Headers.
     * @param string[] $exposeHeaders  Response headers exposed to JavaScript.
     * @param bool     $credentials    Allow cookies/Authorization. Requires explicit origins (not '*').
     * @param int      $maxAge         Preflight cache TTL in seconds.
     * @param string[] $vary           Extra Vary header values.
     */
    public function cors(
        array $origins       = [],
        array $allowHeaders  = [],
        array $exposeHeaders = [],
        bool  $credentials   = false,
        int   $maxAge        = 0,
        array $vary          = [],
    ): static {
        $this->globalCors = compact('origins', 'allowHeaders', 'exposeHeaders', 'credentials', 'maxAge', 'vary');
        return $this;
    }

    // ── Route registration ────────────────────────────────────────────────────

    /**
     * @param list<array{class: class-string<Middleware>, args: array}> $middlewares
     * @param array{origins:string[],allowHeaders:string[],exposeHeaders:string[],credentials:bool,maxAge:int,vary:string[]}|null $cors
     */
    public function add(string $method, string $path, mixed $handler, array $middlewares = [], ?array $cors = null): static
    {
        $this->dispatcher = null;

        $method = strtoupper($method);

        if ($middlewares !== [] || $cors !== null) {
            $stored = ['__handler' => $handler];
            if ($middlewares !== []) {
                $stored['__middlewares'] = $middlewares;
            }
            if ($cors !== null) {
                $stored['__cors'] = $cors;
            }
        } else {
            $stored = $handler;
        }

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
            $method = $request->getMethod();

            if (env('DEBUG', false)) {
                LoggerRegistry::instance('Router')->debug(
                    "Handle ". $request->getClientIp()
                    . " [$method] " . $request->getUri()
                );
            }

            // ── Global CORS applied eagerly (covers 404, 405, and errors too) ─
            if ($this->globalCors !== null) {
                $this->writeCorsHeaders($request, $response, $this->globalCors);
            }

            // ── OPTIONS preflight — intercept before route dispatch ───────────
            if ($method === 'OPTIONS') {
                $this->handlePreflight($request, $response);
                return;
            }

            $result = $this->dispatch($method, $request->getUri());

            // ── Per-route #[CrossOrigin] overrides global CORS ────────────────
            if ($this->globalCors !== null && $result->status === RouteResult::FOUND) {
                $routeCors = $this->extractRouteCors($result->handler);
                if ($routeCors !== null) {
                    $this->writeCorsHeaders($request, $response, $routeCors);
                }
            }

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
                $middlewareDefs = $stored['__middlewares'] ?? [];
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
        if ($e instanceof DebugDumpException) {
            $res->status(200);
            $res->header('Content-Type', 'text/html; charset=utf-8');
            $res->end($this->renderDump($e));
            return;
        }

        $this->logException($e);

        $exc  = ExceptionWrapper::wrap($e);
        $body = $exc->getBody(); // must be first — sets Content-Type via addHeader()
        $res->status($exc->getHttpCode()->value);
        foreach ($exc->getHeader() as $key => $value) {
            $res->header($key, $value);
        }
        $res->end($body);
    }

    private function logException(\Throwable $e): void
    {
        $code   = (int) $e->getCode();
        $logger = LoggerRegistry::instance('Router');

        // Exception carries its own declared log level — highest priority
        if ($e instanceof LogLevelException) {
            $logger->log($e->getLogLevel(), $e->getMessage(), [
                'exception' => $e::class,
                'code'      => $code,
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            return;
        }

        // 4xx signals (ResponseException + subclasses: MiddlewareException, RequestException)
        if ($e instanceof ResponseException && $code >= 400 && $code < 500) {
            $logger->warning($e->getMessage(), [
                'exception' => $e::class,
                'code'      => $code,
            ]);
            return;
        }

        // 5xx and infrastructure failures — error with location
        $logger->error($e->getMessage(), [
            'exception' => $e::class,
            'code'      => $code,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
    }

    // ── CORS internals ────────────────────────────────────────────────────────

    /**
     * Handle an OPTIONS preflight request.
     *
     * Probes the dispatcher with the browser's Access-Control-Request-Method to:
     * 1. Find what HTTP methods are registered for this URI.
     * 2. Retrieve any per-route #[CrossOrigin] config from the matched handler.
     * 3. Respond 204 No Content with the merged CORS headers.
     */
    private function handlePreflight(HttpRequest $req, HttpResponse $res): void
    {
        $requestedMethod = strtoupper(Header::get('Access-Control-Request-Method') ?? 'GET');

        // Probe with the browser's intended method to retrieve handler + CORS config
        $probe     = $this->dispatch($requestedMethod, $req->getUri());
        $routeCors = null;
        $methods   = ['OPTIONS'];

        if ($probe->status === RouteResult::FOUND) {
            $routeCors = $this->extractRouteCors($probe->handler);
            // Find all methods registered for this path via a dummy-method probe
            $all     = $this->dispatch('__CORS__', $req->getUri());
            $methods = $all->status === RouteResult::METHOD_NOT_ALLOWED
                ? array_unique([...$all->allowedMethods, 'OPTIONS'])
                : [$requestedMethod, 'OPTIONS'];
        } elseif ($probe->status === RouteResult::METHOD_NOT_ALLOWED) {
            $methods = array_unique([...$probe->allowedMethods, 'OPTIONS']);
        }
        // else NOT_FOUND: path doesn't exist — respond 204 with minimal headers

        $cors = $routeCors ?? $this->globalCors;
        if ($cors !== null) {
            $this->writeCorsHeaders($req, $res, $cors, $methods, preflight: true);
        }

        $res->status(204);
        $res->end('');
    }

    /**
     * Write CORS headers onto a response.
     *
     * @param array    $cors      CORS config array (same shape as globalCors)
     * @param string[] $methods   HTTP methods to advertise (preflight only)
     * @param bool     $preflight true → also write Allow-Methods / Allow-Headers / Max-Age
     */
    private function writeCorsHeaders(HttpRequest $req, HttpResponse $res, array $cors, array $methods = [], bool $preflight = false): void
    {
        $origins = $cors['origins'];

        // ── Access-Control-Allow-Origin ──────────────────────────────────────
        if (empty($origins)) {
            $res->header('Access-Control-Allow-Origin', '*');
        } elseif (count($origins) === 1) {
            $res->header('Access-Control-Allow-Origin', $origins[0]);
        } else {
            // Reflect the request Origin if it is in the allowlist
            $origin = Header::get('Origin') ?? '';
            if ($origin !== '' && in_array($origin, $origins, true)) {
                $res->header('Access-Control-Allow-Origin', $origin);
                $res->header('Vary', 'Origin');
            }
            // else: no header — browser will block (intentional)
        }

        // ── Credentials ──────────────────────────────────────────────────────
        // credentials: true + wildcard '*' is forbidden by the spec
        if ($cors['credentials'] && !empty($origins)) {
            $res->header('Access-Control-Allow-Credentials', 'true');
        }

        // ── Expose-Headers (regular responses + preflight) ───────────────────
        if (!empty($cors['exposeHeaders'])) {
            $res->header('Access-Control-Expose-Headers', implode(', ', $cors['exposeHeaders']));
        }

        // ── Vary (custom additions) ───────────────────────────────────────────
        if (!empty($cors['vary'])) {
            $res->header('Vary', implode(', ', $cors['vary']));
        }

        if (!$preflight) {
            return;
        }

        // ── Preflight-only headers ────────────────────────────────────────────

        if (!empty($methods)) {
            $res->header('Access-Control-Allow-Methods', implode(', ', $methods));
        }

        if (!empty($cors['allowHeaders'])) {
            $res->header('Access-Control-Allow-Headers', implode(', ', $cors['allowHeaders']));
        } else {
            // Reflect the browser's requested headers (safe default)
            $requested = Header::get('Access-Control-Request-Headers') ?? '';
            if ($requested !== '') {
                $res->header('Access-Control-Allow-Headers', $requested);
            }
        }

        if ($cors['maxAge'] > 0) {
            $res->header('Access-Control-Max-Age', (string) $cors['maxAge']);
        }
    }

    /**
     * Extract per-route CORS config stored by MappingScanner under '__cors'.
     *
     * @return array|null  null if the route has no #[CrossOrigin]
     */
    private function extractRouteCors(mixed $stored): ?array
    {
        if (is_array($stored) && array_key_exists('__cors', $stored)) {
            return $stored['__cors'];
        }
        return null;
    }

    // ── Debug dump renderer ───────────────────────────────────────────────────

    private function renderDump(DebugDumpException $e): string
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
        $runtimeMode = htmlspecialchars(Runtime::mode()->name ?? '', ENT_QUOTES);

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
                    <span style="color:#9e9e9e;font-weight:bold">
                        ({$runtimeMode}) Memory {$memory}
                    </span>
                    <span style="color:#9e9e9e;font-style:italic">Time {$delta}</span>
                </div>
            </div>
            </body>
            HTML;
    }
}
