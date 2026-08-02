<?php

declare(strict_types=1);

use Flytachi\Winter\Kernel\Localization\Locale;
use Flytachi\Winter\Kernel\Http\Response\RenderContext;

if (!function_exists('trans')) {
    /**
     * Translates a given key using the loaded dictionary.
     *
     * Looks up a value by dot-separated key. Parameter style is auto-detected:
     *   - list (sequential keys)   → sprintf substitution: %s, %d, %1$s, …
     *   - associative (string keys) → :name placeholder substitution via strtr
     * Missing key → returned as is (graceful fallback).
     *
     * @param string $key Dot-notation key, e.g. 'auth.welcome'.
     * @param array<int|string,mixed>|null $params sprintf args (list) or :name map (assoc).
     * @return string Translated string or the key itself if not found.
     */
    function trans(string $key, ?array $params = null): string
    {
        return Locale::translate($key, $params ?? []);
    }
}

if (!function_exists('wrImport')) {
    function wrImport(string $resourceName): void
    {
        RenderContext::current()?->import($resourceName);
    }
}

if (!function_exists('wrIsActiveLink')) {
    function wrIsActiveLink(
        string $link,
        string $classNameSuccess = 'active',
        string $classNameNone = ''
    ): string {
        return RenderContext::current()
            ?->isActiveLink($link, $classNameSuccess, $classNameNone) ?? $classNameNone;
    }
}

if (!function_exists('wrContent')) {
    function wrContent(): void
    {
        echo RenderContext::current()?->getResourceContent() ?? '';
    }
}

if (!function_exists('wrData')) {
    function wrData(?string $valueKey = null): mixed
    {
        return RenderContext::current()?->getData($valueKey);
    }
}
