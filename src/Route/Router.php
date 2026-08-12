<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route;

use Flytachi\Winter\Base\Exception\DebugDumpException;
use Flytachi\Winter\Base\Exception\ExceptionLogLevel;
use Flytachi\Winter\Logger\LoggerFactory;
use Flytachi\Winter\DI\ReflectionCache;
use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Kernel\Core\ClassScanner;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Header;
use Flytachi\Winter\Kernel\Http\ParameterResolver;
use Flytachi\Winter\Kernel\Http\Response\Collector\ExceptionCollector;
use Flytachi\Winter\Kernel\Localization\Locale;
use Flytachi\Winter\Kernel\Http\Response\ExceptionWrapper;
use Flytachi\Winter\Kernel\Http\Response\ResponseEntity;
use Flytachi\Winter\Kernel\Http\Response\ResponseException;
use Flytachi\Winter\Kernel\Http\Response\Sendable;
use Flytachi\Winter\Kernel\Http\Cors;
use Flytachi\Winter\Kernel\Http\Health\Health;
use Flytachi\Winter\Kernel\Http\Health\HealthIndicatorInterface;
use Flytachi\Winter\Kernel\Http\Health\Status;
use Flytachi\Winter\Kernel\Plugin;
use Flytachi\Winter\Kernel\Route\Collector\MappingCollector;
use Flytachi\Winter\Kernel\Http\Stereotype\Middleware;
use Flytachi\Winter\Base\HttpCode;

/**
 * Router — dual-mode (Swoole + FPM), Spring Boot-style.
 *
 * ── Route registration (manual) ─────────────────────────────────────────────
 *   $router->get('/users',          [UserController::class, 'index']);
 *   $router->post('/users',         [UserController::class, 'store']);
 *   $router->get('/users/{id:\d+}', [UserController::class, 'show']);
 *   $router->add('GET', '/ping',    fn($req, $res, $p) => ResponseEntity::ok('pong'));
 *
 * ── Attribute-based (preferred) ─────────────────────────────────────────────
 *   $router = Router::fromScan(Kernel::$pathRoot);  // scan the project for controllers
 *
 * ── Global CORS ──────────────────────────────────────────────────────────────
 *   Cors::configure([...]);   // via a WebConfigurer, before the scan
 *   // Per-route override: #[CrossOrigin] attribute on controller class or method
 *
 * ── Static files ─────────────────────────────────────────────────────────────
 *   Not the router's job. Swoole serves them itself, in C, before PHP is reached —
 *   declare the directory with {@see \Flytachi\Winter\Kernel\App\Config\ServerSettings::staticPath()}.
 *
 * ── Dispatch ─────────────────────────────────────────────────────────────────
 *   $router->handle(new SwooleRequest($req), new SwooleResponse($res));
 *   $router->handle(new FpmRequest(),        new FpmResponse());
 */
final class Router
{
    /** @var array<string, array<string, mixed>> [METHOD][path] => handler */
    private array $staticRoutes  = [];

    /** @var list<Route> */
    private array $dynamicRoutes = [];

    private ?Dispatcher $dispatcher = null;

    // ── Route registration ────────────────────────────────────────────────────

    /**
     * Register a single route.
     *
     * Handlers are stored as-is for simple routes; wrapped in an array envelope
     * when middlewares or per-route CORS are present so invoke() can unpack them.
     *
     * @param list<array{class: class-string<Middleware>, args: array}> $middlewares
     * @param array{
     *     origins:string[],
     *     allowHeaders:string[],
     *     exposeHeaders:string[],
     *     credentials:bool,
     *     maxAge:int,
     *     vary:string[]
     * }|null $cors
     * @param int|null $timeout Per-route deadline in seconds from #[Timeout]; 0 opts
     *   the route out, null leaves the global deadline in force.
     */
    public function add(
        string $method,
        string $path,
        mixed $handler,
        array $middlewares = [],
        ?array $cors = null,
        ?int $timeout = null
    ): static {
        $this->dispatcher = null;

        $method = strtoupper($method);

        if ($middlewares !== [] || $cors !== null || $timeout !== null) {
            $stored = ['__handler' => $handler];
            if ($middlewares !== []) {
                $stored['__middlewares'] = $middlewares;
            }
            if ($cors !== null) {
                $stored['__cors'] = $cors;
            }
            if ($timeout !== null) {
                $stored['__timeout'] = $timeout;
            }
        } else {
            $stored = $handler;
        }

        $route = new Route($method, $path, $stored);

        if ($route->isStatic()) {
            if (isset($this->staticRoutes[$method][$path])) {
                throw new \RuntimeException("Ambiguous handler methods mapped for [{$method}] '{$path}'");
            }
            $this->staticRoutes[$method][$path] = $stored;
        } else {
            foreach ($this->dynamicRoutes as $existing) {
                if ($existing->method === $method && $existing->path === $path) {
                    throw new \RuntimeException("Ambiguous handler methods mapped for [{$method}] '{$path}'");
                }
            }
            $this->dynamicRoutes[] = $route;
        }

        return $this;
    }

    public function get(string $path, mixed $handler): static
    {
        return $this->add('GET', $path, $handler);
    }
    public function post(string $path, mixed $handler): static
    {
        return $this->add('POST', $path, $handler);
    }
    public function put(string $path, mixed $handler): static
    {
        return $this->add('PUT', $path, $handler);
    }
    public function patch(string $path, mixed $handler): static
    {
        return $this->add('PATCH', $path, $handler);
    }
    public function delete(string $path, mixed $handler): static
    {
        return $this->add('DELETE', $path, $handler);
    }
    public function options(string $path, mixed $handler): static
    {
        return $this->add('OPTIONS', $path, $handler);
    }

    // ── Factory methods ───────────────────────────────────────────────────────

    /**
     * Scan $rootDir for controllers and exception handlers via a unified Scanner pass.
     *
     * `$cache` is the project's class-list cache — the same file the boot scan already
     * built, not a route cache. Routes are still compiled from attributes on every
     * start; what the cache removes is the second walk of the tree, which is where the
     * cost was: measured on 304 files, the walk is ~21 ms and ~2 MB, the attribute pass
     * over an already-loaded tree is 0.2 ms. Those 2 MB are paid by every forked worker,
     * so the saving is not only at boot.
     *
     * The cache is ignored when `$exclude` is given: the cached list was built with the
     * scan's own exclusions, so honouring extra ones would need the walk anyway — and
     * silently skipping them would be worse than being slow.
     *
     * @param string[]    $exclude Directories to skip (vendor/ is always excluded)
     * @param string|null $cache   Class-list cache path; null always walks the filesystem
     */
    public static function fromScan(string $rootDir, array $exclude = [], ?string $cache = null): static
    {
        $router             = new static();
        $mappingCollector   = new MappingCollector($router);
        $exceptionCollector = new ExceptionCollector();

        ClassScanner::scanner($rootDir, $exclude === [] ? $cache : null)
            ->exclude($exclude)
            ->collect($mappingCollector)
            ->collect($exceptionCollector)
            ->execute();

        foreach (Plugin::getPlugins() as $prefix => $path) {
            $pluginSrc = $path . '/src';
            if (is_dir($pluginSrc)) {
                ClassScanner::scanner($pluginSrc)
                    ->collect(new MappingCollector($router, $prefix))
                    ->execute();
            }
        }

        if ($health = Health::getConfig()) {
            Health::setRootDir($rootDir);
            Health::setMappings($router->getRoutesSummary());
            $router->registerHealth($health['indicator'], $health['middleware']);
        }

        ExceptionWrapper::setHandlers($exceptionCollector->getHandlers());
        return $router;
    }

    /** Add attribute-scanned routes from $rootDir to this Router instance. */
    public function scan(string $rootDir, array $exclude = []): static
    {
        ClassScanner::scanner($rootDir)
            ->exclude($exclude)
            ->collect(new MappingCollector($this))
            ->execute();
        return $this;
    }

    // ── Health / Actuator ─────────────────────────────────────────────────────

    private function registerHealth(string $indicatorClass, ?string $middlewareClass): void
    {
        $middlewares = $middlewareClass !== null
            ? [['class' => $middlewareClass, 'args' => []]]
            : [];

        $handler = static function (
            HttpRequest $req,
            HttpResponse $res,
            array $params
        ) use ($indicatorClass): ResponseEntity {
            $method = $params['method'] ?? 'health';

            /** @var HealthIndicatorInterface $indicator */
            $indicator = new $indicatorClass();

            if (!method_exists($indicator, $method)) {
                throw new ResponseException('Actuator endpoint not found', HttpCode::NOT_FOUND);
            }

            $body = $indicator->{$method}();

            return ResponseEntity::status(self::healthCode($method, $body))->body($body);
        };

        $this->add('GET', '/actuator', $handler, $middlewares);
        $this->add('GET', '/actuator/{method}', $handler, $middlewares);
    }

    /**
     * The response code carrying the health verdict: `down` → 503, everything else → 200.
     *
     * Without this the endpoint answered 200 while reporting `status: down` inside, so
     * every consumer that reads the code rather than the body — a container health check,
     * a k8s liveness/readiness probe, a load balancer — saw a dead application as healthy.
     *
     * `degraded` deliberately stays 200: it means working worse, not not working, and a
     * probe that pulls the instance out of rotation over it would turn a partial outage
     * into a full one.
     *
     * Only `health` reports a status; `info`, `metrics` and the rest are plain reads.
     */
    private static function healthCode(string $method, mixed $body): HttpCode
    {
        if ($method !== 'health' || !is_array($body)) {
            return HttpCode::OK;
        }
        $status = $body['status'] ?? null;
        $status = $status instanceof Status ? $status->value : $status;

        return is_string($status) && strtolower($status) === Status::Down->value
            ? HttpCode::SERVICE_UNAVAILABLE
            : HttpCode::OK;
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    /**
     * Resolve $method + $uri to a RouteResult.
     * Strips the query string before matching.
     * Builds the Dispatcher lazily on first call and reuses it for all subsequent requests.
     */
    public function dispatch(string $method, string $uri): RouteResult
    {
        if ($this->dispatcher === null) {
            $this->dispatcher = new Dispatcher($this->staticRoutes, $this->dynamicRoutes);
        }

        $path = ($pos = strpos($uri, '?')) !== false ? substr($uri, 0, $pos) : $uri;

        return $this->dispatcher->dispatch($method, $path);
    }

    /**
     * Handle an HTTP request end-to-end. Works identically in Swoole and FPM.
     *
     * Pipeline:
     *   1. Header::init()            — snapshot request headers into the static bag
     *   2. Locale::initFromRequest() — detect Accept-Language / locale cookie
     *   3. Swoole context            — stamp start time, method, uri in coroutine ctx
     *   4. Global CORS headers       — applied before dispatch (covers 404 / 500 too)
     *   5. OPTIONS preflight         — returns 204 before handler invocation
     *   6. Route dispatch            — O(1) static map → chunked regex dynamic scan
     *   7. Per-route #[CrossOrigin]  — overrides global CORS if present
     *   8. Middleware before()       — run in declaration order
     *   9. Controller method         — resolved via ReflectionCache + ParameterResolver
     *  10. Middleware after()        — run in reverse order
     *  11. Response serialise        — Sendable::send() or ResponseEntity::ok()->send()
     *  12. Error handling            — ExceptionWrapper maps Throwable → HTTP response
     */
    public function handle(HttpRequest $request, HttpResponse $response): void
    {
        Header::init($request);
        Locale::initFromRequest();

        if (Runtime::isSwooleCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            $ctx['__request_start']  = microtime(true);
            $ctx['__request_method'] = $request->getMethod();
            $ctx['__request_uri']    = $request->getUri();
        }

        // Watched from here, with the global deadline; a route carrying its own
        // #[Timeout] adjusts it below, once dispatch has said which route this is.
        // Released via defer so it happens however the request ends — including the
        // cancellation the watchdog itself raises.
        //
        // The time already spent queueing counts against the deadline. Under
        // worker_max_concurrency a request's coroutine is not created until the worker
        // lets it through, so without this a request that waited three seconds would
        // start a fresh thirty — while the client has been waiting the whole time.
        $watched = RequestWatchdog::register(elapsed: self::waitedInQueue($request));
        if ($watched !== null) {
            \Swoole\Coroutine::defer(static fn() => RequestWatchdog::release($watched));
        }

        try {
            $method = $request->getMethod();

            if (env('DEBUG', false)) {
                LoggerFactory::getLogger(self::class)->debug(
                    $request->getClientIp() . " -- $method " . $request->getUri()
                );
            }

            // ── Global CORS applied eagerly (covers 404, 405, and errors too) ─
            if (Cors::getConfig() !== null) {
                $this->writeCorsHeaders($request, $response, Cors::getConfig());
            }

            // ── OPTIONS preflight — intercept before route dispatch ───────────
            if ($method === 'OPTIONS') {
                $this->handlePreflight($request, $response);
                return;
            }

            $result = $this->dispatch($method, $request->getUri());

            // ── Per-route #[CrossOrigin] overrides global CORS ────────────────
            if (Cors::getConfig() !== null && $result->status === RouteResult::FOUND) {
                $routeCors = $this->extractRouteCors($result->handler);
                if ($routeCors !== null) {
                    $this->writeCorsHeaders($request, $response, $routeCors);
                }
            }

            // ── Per-route #[Timeout] overrides the global deadline ────────────
            if ($result->status === RouteResult::FOUND) {
                $routeTimeout = $this->extractRouteTimeout($result->handler);
                if ($routeTimeout !== null) {
                    RequestWatchdog::extend($watched, (float) $routeTimeout);
                }
            }

            switch ($result->status) {
                case RouteResult::FOUND:
                    $this->invoke($result->handler, $request, $response, $result->params);
                    break;
                case RouteResult::METHOD_NOT_ALLOWED:
                    LoggerFactory::contextStorage()->set('method', $request->getMethod());
                    LoggerFactory::contextStorage()->set('path', $request->getUri());
                    throw new ResponseException(
                        'Method Not Allowed',
                        HttpCode::METHOD_NOT_ALLOWED
                    )->withHeader('Allow', implode(', ', $result->allowedMethods));
                default:
                    LoggerFactory::contextStorage()->set('method', $request->getMethod());
                    LoggerFactory::contextStorage()->set('path', $request->getUri());
                    if (env('DEBUG', false)) {
                        throw new ResponseException('Not Found [ '
                            . $request->getMethod() . ' '
                            . $request->getUri() . ' ]',
                            HttpCode::NOT_FOUND
                        );
                    } else {
                        throw new ResponseException('Not Found', HttpCode::NOT_FOUND);
                    }
            }

            match ($result->status) {
                RouteResult::FOUND => $this->invoke($result->handler, $request, $response, $result->params),
                RouteResult::METHOD_NOT_ALLOWED => throw new ResponseException(
                    'Method Not Allowed',
                    HttpCode::METHOD_NOT_ALLOWED
                )->withHeader('Allow', implode(', ', $result->allowedMethods)),
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
                $mw = Container::getInstance()->make($def['class'], $def['args']);
                $mw->before($req, $res);
                $stack[] = $mw;
            }

            // ── Resolve handler and invoke ────────────────────────────────────
            if (is_array($handler) && is_string($handler[0])) {
                [$class, $methodName] = $handler;
                $object    = Container::getInstance()->make($class);
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

            // A handler that swallowed the watchdog's cancellation arrives here having
            // completed no I/O — every wait was cut short — so its result was built from
            // queries that never ran. Answer the deadline instead of that.
            if (RequestWatchdog::isCurrentExpired()) {
                throw new ResponseException('Gateway Timeout', HttpCode::GATEWAY_TIMEOUT);
            }

            // ── Serialize return value ────────────────────────────────────────
            if ($result instanceof Sendable) {
                $result->send($res, $req);
            } elseif ($result !== null) {
                ResponseEntity::ok($result)->send($res, $req);
            }
        } catch (\Throwable $e) {
            $this->sendError($e, $res);
        }
    }

    private function sendError(\Throwable $e, HttpResponse $res): void
    {
        // Past the deadline, whatever surfaced is a consequence of the cancellation —
        // typically Swoole\Coroutine\CanceledException, raised wherever the request
        // happened to be waiting. On its own that is an empty message with code 0, which
        // would be logged as a server fault and answered 500. Say what actually happened.
        $e = $this->asTimeout($e);

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
        $logger = LoggerFactory::getLogger('Router');

        // Exception carries its own declared log level — highest priority
        if ($e instanceof ExceptionLogLevel) {
            $logger->log($e->getLogLevel(), $e->getMessage(), [
                'code'      => $code,
                'exception' => $e::class,
                'file'      => $e->getFile() . ':' . $e->getLine()
            ]);
            return;
        }

        // 5xx and infrastructure failures — error with location
        $logger->error($e->getMessage(), [
            'code'      => $code,
            'exception' => $e::class,
            'file'      => $e->getFile() . ':' . $e->getLine()
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

        $cors = $routeCors ?? Cors::getConfig();
        if ($cors !== null) {
            $this->writeCorsHeaders($req, $res, $cors, $methods, preflight: true);
        }

        $res->status(204);
        $res->end('');
    }

    /**
     * Write CORS headers onto a response.
     *
     * @param array    $cors      CORS config array (same shape as Cors::getConfig())
     * @param string[] $methods   HTTP methods to advertise (preflight only)
     * @param bool     $preflight true → also write Allow-Methods / Allow-Headers / Max-Age
     */
    private function writeCorsHeaders(
        HttpRequest $req,
        HttpResponse $res,
        array $cors,
        array $methods = [],
        bool $preflight = false
    ): void {
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

    /**
     * Extract the per-route deadline stored by {@see Collector\MappingCollector} under
     * '__timeout' — the seconds from a route's own `#[Timeout]`, or null when it has
     * none and the global deadline stands. `0` there means the route opts out.
     */
    private function extractRouteTimeout(mixed $stored): ?int
    {
        if (is_array($stored) && array_key_exists('__timeout', $stored)) {
            return $stored['__timeout'];
        }
        return null;
    }

    /**
     * Presents anything raised after the deadline as `504 Gateway Timeout`.
     *
     * Applied where the response is built rather than where the request is dispatched,
     * because {@see invoke()} has a `catch` of its own: it answered the raw
     * `CanceledException` — code 0, empty message, logged as a server fault and sent as
     * 500 — before the outer handler ever saw it, and the outer 504 then arrived too late
     * to change the response and only added a second log line.
     *
     * A `ResponseException` already carrying 504 is left alone, so re-wrapping cannot
     * stack. Everything else on a request that did not time out passes through untouched:
     * a request that timed out *and* had a real bug still reports the bug as the cause.
     */
    private function asTimeout(\Throwable $e): \Throwable
    {
        if ($e instanceof ResponseException && $e->getCode() === HttpCode::GATEWAY_TIMEOUT->value) {
            return $e;
        }
        if (!RequestWatchdog::isCurrentExpired()) {
            return $e;
        }

        return new ResponseException('Gateway Timeout', HttpCode::GATEWAY_TIMEOUT, $e);
    }

    /**
     * Seconds this request spent waiting to be picked up, before any of it ran.
     *
     * Swoole stamps `request_time_float` when the packet arrives, which is *before*
     * `worker_max_concurrency` decides whether there is room to run it — verified with a
     * limit of one and a 0.3-second handler: five simultaneous requests reported 0.000,
     * 0.301, 0.603, 0.904 and 1.206 seconds of waiting.
     *
     * Returns 0.0 when the stamp is missing or nonsensical (a clock adjustment between
     * arrival and now would otherwise charge the request for it), so the deadline then
     * behaves exactly as it did before.
     */
    private static function waitedInQueue(HttpRequest $request): float
    {
        $arrived = $request->getServerParam('request_time_float');

        return is_numeric($arrived) ? max(0.0, microtime(true) - (float) $arrived) : 0.0;
    }

    // ── Debug helpers ─────────────────────────────────────────────────────────

    /** @return list<array{method:string, path:string, handler:string}> */
    public function getRoutesSummary(): array
    {
        $routes = [];

        foreach ($this->staticRoutes as $method => $paths) {
            foreach ($paths as $path => $handler) {
                $routes[] = ['method' => $method, 'path' => $path, 'handler' => $this->formatHandler($handler)];
            }
        }

        foreach ($this->dynamicRoutes as $route) {
            $routes[] = [
                'method' => $route->method,
                'path' => $route->path,
                'handler' => $this->formatHandler($route->handler)
            ];
        }

        usort($routes, static fn($a, $b) => strcmp($a['path'], $b['path']));

        return $routes;
    }

    private function formatHandler(mixed $handler): string
    {
        $h = is_array($handler) && array_key_exists('__handler', $handler) ? $handler['__handler'] : $handler;

        if (is_array($h) && count($h) === 2) {
            $class = is_string($h[0]) ? $h[0] : get_class($h[0]);
            return $class . '::' . $h[1];
        }

        return is_string($h) ? $h : '{closure}';
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
                'object'            => '<span style="color:#ff7033">'
                        . htmlspecialchars(print_r($value, true), ENT_QUOTES) . '</span>',
                'array'             => '<span style="color:#cb71ff">'
                        . htmlspecialchars(print_r($value, true), ENT_QUOTES) . '</span>',
                'string'            => '<span style="color:#e4ff6c">'
                        . htmlspecialchars(var_export($value, true), ENT_QUOTES) . '</span>',
                default             => '<span style="color:#fa5151">'
                        . htmlspecialchars(var_export($value, true), ENT_QUOTES) . '</span>',
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
