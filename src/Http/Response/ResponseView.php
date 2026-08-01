<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Kernel;

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
 * receives all $data keys plus $content (the rendered resource); the **resource** is
 * the page, and it receives the $data keys. Both are resolved under the same root, so
 * the directory is named after neither — `views` covers both, with layouts
 * conventionally under `views/layouts`.
 */
class ResponseView implements Sendable
{
    /** Directory under {@see Kernel::$pathResource} holding the views. */
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
     * Inside the template, $content holds the already-rendered resource HTML.
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

        $response->end($this->renderContent());
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function renderContent(): string
    {
        RenderContext::push(self::$basePath, $this->data, $this->templateName, $this->resourceName);
        try {
            $resource = $this->capture($this->resourcePath(), $this->data);
            RenderContext::current()?->setResourceContent($resource);

            $html = $this->templateName !== null
                ? $this->capture($this->templatePath(), $this->data)
                : $resource;

            return $html . RenderContext::current()?->debugger();
        } finally {
            RenderContext::pop();
        }
    }

    private function capture(string $filePath, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include $filePath;
        return (string) ob_get_clean();
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
