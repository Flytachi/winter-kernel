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
 *   RenderContext::pop()       — after rendering finishes (via finally)
 */
final class RenderContext
{
    // ── Static stack (FPM) ────────────────────────────────────────────────────
    private static array $stack = [];

    private array $resourceAdditional = [];

    private function __construct(
        private readonly string $basePath,
        private readonly array $data,
        private readonly ?string $templateName,
        private readonly string $resourceName,
    ) {
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public static function push(
        string $basePath,
        array $data,
        ?string $templateName,
        string $resourceName,
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

    private ?string $wrContent = null;

    public function setResourceContent(string $content): void
    {
        $this->wrContent = $content;
    }

    public function getResourceContent(): string
    {
        return $this->wrContent ?: '';
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

        $this->resourceAdditional[] = $path;

        extract($this->data, EXTR_SKIP);
        include $path;
    }

    public function isActiveLink(
        array|string $link,
        string $classNameSuccess = 'active',
        string $classNameNone = '',
    ): string {
        $uri = Runtime::isSwooleCoroutine()
            ? (\Swoole\Coroutine::getContext()['__request_uri'] ?? '/')
            : ($_SERVER['REQUEST_URI'] ?? '/');

        if (is_array($link)) {
            return in_array($uri, $link, true) ? $classNameSuccess : $classNameNone;
        }

        return $uri === $link ? $classNameSuccess : $classNameNone;
    }
}
