<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Kernel\Core\RequestLocal;

/**
 * Per-request render state behind the `wr*` template helpers.
 *
 * Templates are plain PHP includes, and the helpers that read them — `wrContent()`,
 * `wrData()`, `wrImport()`, `wrIsActiveLink()` — are free functions with no parameter
 * through which the current render could be handed in. This class is that missing
 * parameter. It is kept in {@see RequestLocal}, so it is isolated per coroutine under
 * Swoole and per process under FPM without the caller knowing which runtime it is on.
 *
 * Lifecycle (managed by ResponseView::renderContent()):
 *   RenderContext::push(...)   — before rendering begins
 *   RenderContext::current()   — inside any template or partial
 *   RenderContext::pop()       — after rendering finishes (via finally)
 *
 * A stack rather than a single slot: a render nested inside another one must not leave
 * the outer template reading the inner one's data once it finishes.
 */
final class RenderContext
{
    /** Key the render stack is stored under in {@see RequestLocal}. */
    private const string STACK_KEY = '__render_stack';

    private ?string $resourceContent = null;

    private function __construct(
        private readonly string $basePath,
        private readonly array $data,
        private readonly string $requestUri,
    ) {
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public static function push(string $basePath, array $data, string $requestUri): void
    {
        // Link matching sees the path only. FPM hands over $_SERVER['REQUEST_URI'], which
        // carries the query string, while Swoole's request_uri does not — left as is, the
        // same menu entry would light up on one runtime and stay dark on the other as soon
        // as a `?page=2` appeared. Router::dispatch() trims it the same way.
        $path = ($pos = strpos($requestUri, '?')) !== false ? substr($requestUri, 0, $pos) : $requestUri;

        $stack   = RequestLocal::get(self::STACK_KEY, []);
        $stack[] = new self($basePath, $data, $path);
        RequestLocal::set(self::STACK_KEY, $stack);
    }

    public static function pop(): void
    {
        $stack = RequestLocal::get(self::STACK_KEY, []);
        array_pop($stack);
        RequestLocal::set(self::STACK_KEY, $stack);
    }

    public static function current(): ?self
    {
        $stack = RequestLocal::get(self::STACK_KEY, []);
        return $stack === [] ? null : end($stack);
    }

    // ── Template API ──────────────────────────────────────────────────────────

    public function setResourceContent(string $content): void
    {
        $this->resourceContent = $content;
    }

    public function getResourceContent(): string
    {
        return $this->resourceContent ?: '';
    }

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

        // $path and $resourceName stay behind: an include inherits every local of the
        // method it sits in, and EXTR_SKIP would then keep a $data key of the same name
        // from ever reaching the partial.
        $this->includeTemplate($path);
    }

    /**
     * Includes a partial with only the render data in scope.
     *
     * The one local this leaves visible is deliberately named `$__path`: anything an
     * application would plausibly put in $data — `path`, `title`, `content` — must reach
     * the template intact. `$data` (the whole array) is offered on top, as in a page.
     */
    private function includeTemplate(string $__path): void
    {
        // extract() takes its array by reference, so it cannot be handed $this->data
        // directly — the property is readonly and PHP rejects the indirect modification.
        $data = $this->data;
        extract($data, EXTR_SKIP);
        include $__path;
    }

    /**
     * @param array<string>|string $link One URI, or several that all mark the item active
     *   — a section whose sub-pages should keep the same menu entry highlighted.
     */
    public function isActiveLink(
        array|string $link,
        string $classNameSuccess = 'active',
        string $classNameNone = '',
    ): string {
        if (is_array($link)) {
            return in_array($this->requestUri, $link, true) ? $classNameSuccess : $classNameNone;
        }

        return $this->requestUri === $link ? $classNameSuccess : $classNameNone;
    }
}
