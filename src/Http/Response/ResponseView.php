<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Kernel;

/**
 * PHP template response — port of ViewBase + View.
 *
 * Views live in `resources/views` by default; no configuration is needed. Override
 * the root only for a non-standard layout:
 *   ResponseView::setBasePath(__DIR__ . '/theme');
 *
 * Factory methods:
 *   ResponseView::view('user/profile', ['user' => $user])
 *   ResponseView::render('layouts/main', 'user/profile', ['user' => $user])
 *
 * The two names are not interchangeable: the **template** is the layout, and it
 * receives all $data keys, with the rendered resource emitted by wrContent(); the
 * **resource** is the page, and it receives the $data keys. Both are resolved under the
 * same root, so the directory is named after neither — `views` covers both, with
 * layouts conventionally under `views/layouts`.
 *
 * @link https://winterframe.net/docs/views Layouts, resources and template helpers
 */
final class ResponseView implements Sendable
{
    /** Directory under {@see Kernel::$pathResource} holding the views. */
    use CarriesCookies;

    private const string DEFAULT_DIR = 'views';

    private static string $basePath = '';

    private ?string $templateName;
    private string $resourceName;
    private array $data;
    private HttpCode $httpCode;
    private array $extraHeaders = [];

    private function __construct(
        ?string $templateName,
        string $resourceName,
        array $data,
        HttpCode $httpCode,
    ) {
        if (empty(self::getBasePath())) {
            self::setBasePath(Kernel::$pathResource . '/' . self::DEFAULT_DIR);
        }
        $this->templateName = $templateName;
        $this->resourceName = $resourceName;
        $this->data         = $data;
        $this->httpCode     = $httpCode;

        if ($templateName !== null && !file_exists($this->templatePath())) {
            throw new \RuntimeException("View template not found: {$this->templatePath()}");
        }
        if (!file_exists($this->resourcePath())) {
            throw new \RuntimeException("View resource not found: {$this->resourcePath()}");
        }
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/\\');
    }

    public static function getBasePath(): string
    {
        return self::$basePath;
    }

    // ── Factory methods ───────────────────────────────────────────────────────

    /**
     * Render a resource without a layout template.
     */
    public static function view(
        string $resourceName,
        array $data = [],
        HttpCode $httpCode = HttpCode::OK,
    ): static {
        return new static(null, $resourceName, $data, $httpCode);
    }

    /**
     * Render a resource wrapped inside a layout template.
     * Inside the template, wrContent() emits the already-rendered resource HTML.
     */
    public static function render(
        string $templateName,
        string $resourceName,
        array $data = [],
        HttpCode $httpCode = HttpCode::OK,
    ): static {
        return new static($templateName, $resourceName, $data, $httpCode);
    }

    // ── Builder ───────────────────────────────────────────────────────────────

    public function header(string $name, string $value): static
    {
        $this->extraHeaders[$name] = $value;
        return $this;
    }

    // ── Sendable ──────────────────────────────────────────────────────────────

    public function send(HttpResponse $response, HttpRequest $request): void
    {
        $response->status($this->httpCode->value);
        $response->header('Content-Type', 'text/html; charset=utf-8');

        foreach ($this->extraHeaders as $name => $value) {
            $response->header($name, $value);
        }

        $this->writeCookies($response);

        $response->end($this->renderContent($request->getUri()));
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * @param string $requestUri URI of the request being answered, for wrIsActiveLink().
     */
    private function renderContent(string $requestUri): string
    {
        RenderContext::push(self::$basePath, $this->data, $requestUri);
        try {
            $resource = $this->capture($this->resourcePath(), $this->data);
            RenderContext::current()?->setResourceContent($resource);

            $html = $this->templateName !== null
                ? $this->capture($this->templatePath(), $this->data)
                : $resource;

            return $html;
        } finally {
            RenderContext::pop();
        }
    }

    private function capture(string $filePath, array $data): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            $this->includeTemplate($filePath, $data);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            // A template that throws halfway leaves its buffer — and any it opened
            // itself — unclosed. Drop them, so the half-rendered page cannot end up
            // prepended to whatever response the error is turned into.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * Includes a view with only the render data in scope.
     *
     * An include inherits every local of the method it sits in, and EXTR_SKIP then keeps
     * a $data key of the same name from ever reaching the template — so the locals left
     * visible here are named `$__path` / `$__data`, which no application would use.
     * `$data` (the whole array) is offered on top, matching what a partial sees.
     */
    private function includeTemplate(string $__path, array $__data): void
    {
        $data = $__data;
        extract($__data, EXTR_SKIP);
        include $__path;
    }

    private function resourcePath(): string
    {
        return self::$basePath . '/' . ltrim($this->resourceName, '/\\') . '.php';
    }

    private function templatePath(): string
    {
        return self::$basePath . '/' . ltrim($this->templateName ?? '', '/\\') . '.php';
    }
}
