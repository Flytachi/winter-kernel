<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response;

use Flytachi\Winter\Base\Runtime;

/**
 * Per-request render context for PHP template helpers.
 *
 * FPM  — stored in a static stack (one request per process, no concurrency).
 * Swoole — stored in Coroutine::getContext() (isolated per coroutine = per request).
 *
 * Lifecycle (managed by ResponseView::renderContent()):
 *   RenderContext::push(...)   — before rendering begins
 *   RenderContext::current()   — inside any template or partial
 *   RenderContext::pop()       — after debug output is appended (via finally)
 *
 * Debug meta (only when DEBUG=true, set by Router before send()):
 *   RenderContext::setMeta($class, $method)
 *   RenderContext::setRoutes($routes)
 */
final class RenderContext
{
    // ── Static stack (FPM) ────────────────────────────────────────────────────
    private static array $stack = [];

    // ── Pending debug meta (Router → push, consumed once) ────────────────────
    private static ?string $pendingController = null;
    private static ?string $pendingMethod     = null;
    private static array   $pendingRoutes     = [];

    // ── Instance fields ───────────────────────────────────────────────────────
    private ?string $controllerClass;
    private ?string $controllerMethod;
    private array   $routes             = [];
    private array   $resourceAdditional = [];

    private function __construct(
        private readonly string  $basePath,
        private readonly array   $data,
        private readonly ?string $templateName,
        private readonly string  $resourceName,
    ) {
        [$this->controllerClass, $this->controllerMethod] = self::consumeMeta();
        $this->routes = self::consumeRoutes();
    }

    // ── Debug meta (called by Router, only when DEBUG=true) ───────────────────

    public static function setMeta(string $controllerClass, string $controllerMethod): void
    {
        if (Runtime::isSwooleCoroutine()) {
            \Swoole\Coroutine::getContext()['__render_meta'] = [$controllerClass, $controllerMethod];
        } else {
            self::$pendingController = $controllerClass;
            self::$pendingMethod     = $controllerMethod;
        }
    }

    /** @param list<array{method:string, path:string, handler:string}> $routes */
    public static function setRoutes(array $routes): void
    {
        if (Runtime::isSwooleCoroutine()) {
            \Swoole\Coroutine::getContext()['__render_routes'] = $routes;
        } else {
            self::$pendingRoutes = $routes;
        }
    }

    private static function consumeMeta(): array
    {
        if (Runtime::isSwooleCoroutine()) {
            $co   = \Swoole\Coroutine::getContext();
            $meta = $co['__render_meta'] ?? [null, null];
            unset($co['__render_meta']);
            return $meta;
        }
        $meta = [self::$pendingController, self::$pendingMethod];
        self::$pendingController = null;
        self::$pendingMethod     = null;
        return $meta;
    }

    private static function consumeRoutes(): array
    {
        if (Runtime::isSwooleCoroutine()) {
            $co     = \Swoole\Coroutine::getContext();
            $routes = $co['__render_routes'] ?? [];
            unset($co['__render_routes']);
            return $routes;
        }
        $routes              = self::$pendingRoutes;
        self::$pendingRoutes = [];
        return $routes;
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public static function push(
        string  $basePath,
        array   $data,
        ?string $templateName,
        string  $resourceName,
    ): void {
        $ctx = new self($basePath, $data, $templateName, $resourceName);

        if (Runtime::isSwooleCoroutine()) {
            $co = \Swoole\Coroutine::getContext();
            $co['__render_stack'] ??= [];
            $co['__render_stack'][] = $ctx;
        } else {
            self::$stack[] = $ctx;
        }
    }

    public static function pop(): void
    {
        if (Runtime::isSwooleCoroutine()) {
            $co = \Swoole\Coroutine::getContext();
            if (!empty($co['__render_stack'])) {
                array_pop($co['__render_stack']);
            }
        } else {
            array_pop(self::$stack);
        }
    }

    public static function current(): ?self
    {
        if (Runtime::isSwooleCoroutine()) {
            $stack = \Swoole\Coroutine::getContext()['__render_stack'] ?? [];
            return !empty($stack) ? end($stack) : null;
        }
        return !empty(self::$stack) ? end(self::$stack) : null;
    }

    // ── Template API ──────────────────────────────────────────────────────────

    public function getData(?string $key = null): mixed
    {
        return $key === null ? $this->data : ($this->data[$key] ?? null);
    }

    public function import(string $resourceName): void
    {
        $path = $this->basePath . '/' . ltrim($resourceName, '/\\') . '.php';

        if (!file_exists($path)) {
            throw new \RuntimeException("View import not found: $path");
        }

        $this->resourceAdditional[] = $path;

        extract($this->data, EXTR_SKIP);
        include $path;
    }

    public function isActiveLink(
        array|string $link,
        string $classNameSuccess = 'active',
        string $classNameNone = '',
    ): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        if (is_array($link)) {
            return in_array($uri, $link, true) ? $classNameSuccess : $classNameNone;
        }

        return $uri === $link ? $classNameSuccess : $classNameNone;
    }

    // ── Debug panel ───────────────────────────────────────────────────────────

    public function debugger(): string
    {
        if (!env('DEBUG', false)) {
            return '';
        }

        if (Runtime::isSwooleCoroutine()) {
            $start = \Swoole\Coroutine::getContext()['__request_start'] ?? microtime(true);
        } else {
            $start = defined('WINTER_STARTUP_TIME') ? WINTER_STARTUP_TIME : microtime(true);
        }
        $delta = max(round(microtime(true) - $start, 3), 0.001);
        $memory = function_exists('bytes')
            ? bytes(memory_get_usage(), 'MiB')
            : round(memory_get_usage() / 1048576, 2) . ' MiB';

        $method = htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'CLI', ENT_QUOTES);
        $uri    = htmlspecialchars($_SERVER['REQUEST_URI']    ?? '/', ENT_QUOTES);

        $templateDisplay   = $this->templateName
            ? str_replace($this->basePath, '', $this->basePath . '/' . $this->templateName . '.php')
            : null;
        $resourceDisplay   = str_replace($this->basePath, '', $this->basePath . '/' . $this->resourceName . '.php');
        $additionalDisplay = array_map(
            fn($p) => str_replace($this->basePath, '', $p),
            $this->resourceAdditional
        );

        $general = $this->esc(print_r([
            'sapi'                  => PHP_SAPI,
            'runtime'               => Runtime::mode()->name,
            'timezone'              => date_default_timezone_get(),
            'date'                  => date(DATE_ATOM),
            'controllerClass'       => $this->controllerClass,
            'controllerClassMethod' => $this->controllerMethod,
            'template'              => $templateDisplay,
            'resource'              => $resourceDisplay,
            'resourceAdditional'    => $additionalDisplay,
            'resourceData'          => $this->data,
        ], true));

        $routingRows = '';
        foreach ($this->routes as $r) {
            $m       = htmlspecialchars($r['method'], ENT_QUOTES);
            $p       = htmlspecialchars($r['path'],   ENT_QUOTES);
            $h       = htmlspecialchars($r['handler'], ENT_QUOTES);
            $color   = self::methodColor($r['method']);
            $routingRows .= <<<HTML
                <tr style="border-bottom:1px solid #1a1a1a">
                    <td style="padding:4px 10px">
                        <span style="background:$color;color:#fff;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:bold">$m</span>
                    </td>
                    <td style="padding:4px 10px;color:#c3fc04;font-family:monospace">$p</td>
                    <td style="padding:4px 10px;color:#7ecfff;font-family:monospace;font-size:12px">$h</td>
                </tr>
            HTML;
        }

        $routingTable = $routingRows !== ''
            ? <<<HTML
                <div style="overflow-y:auto;max-height:300px">
                    <table style="width:100%;border-collapse:collapse;font-size:13px">
                        <thead>
                            <tr style="color:#555;font-size:11px;text-transform:uppercase">
                                <th style="padding:4px 10px;text-align:left;width:90px">Method</th>
                                <th style="padding:4px 10px;text-align:left">Path</th>
                                <th style="padding:4px 10px;text-align:left">Handler</th>
                            </tr>
                        </thead>
                        <tbody>$routingRows</tbody>
                    </table>
                </div>
            HTML
            : '<div style="padding:10px;color:#555;font-style:italic">No routes registered</div>';

        $globals = '';
        foreach ($GLOBALS as $name => $info) {
            if (empty($info)) {
                continue;
            }
            $safeName    = htmlspecialchars(ltrim($name, '_'), ENT_QUOTES);
            $safeContent = $this->esc(print_r($info, true));
            $globals    .= <<<HTML
                <input type="checkbox" id="debug-item_$safeName">
                <label for="debug-item_$safeName">$safeName</label>
                <div class="winter_debug-accordion-body">
                    <pre>$safeContent</pre>
                </div>
            HTML;
        }

        return <<<HTML
            <link rel="stylesheet" type="text/css" href="/static/winter/debug.css"/>
            <script type="text/javascript" src="/static/winter/debug.js"></script>
            <button id="winter_debug-btn" onclick="WinterDebugBar()"><em>Debug</em></button>

            <div id="winter_debug-bar">
                <div id="winter_debug-bar_body-indicator">
                    <span style="color:#c3fc04;font-weight:bold;margin-right:12px">[$method]</span>
                    <span style="color:#fff;margin-right:20px">$uri</span>
                    <b>Memory:</b> $memory &nbsp;|&nbsp;
                    <b>Time:</b> $delta sec
                </div>
                <div id="winter_debug-bar_body-accordion-container">

                    <input type="checkbox" id="winter_debug-item_general">
                    <label for="winter_debug-item_general">GENERAL</label>
                    <div class="winter_debug-accordion-body">
                        <pre>$general</pre>
                    </div>

                    <input type="checkbox" id="winter_debug-item_routing">
                    <label for="winter_debug-item_routing">ROUTING</label>
                    <div class="winter_debug-accordion-body">
                        $routingTable
                    </div>

                    <hr>
                    $globals
                </div>
            </div>
        HTML;
    }

    private static function methodColor(string $method): string
    {
        return match (strtoupper($method)) {
            'GET'     => '#28a745',
            'POST'    => '#007bff',
            'PUT'     => '#fd7e14',
            'PATCH'   => '#6f42c1',
            'DELETE'  => '#dc3545',
            'OPTIONS' => '#6c757d',
            default   => '#343a40',
        };
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
