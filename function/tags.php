<?php

declare(strict_types=1);

use Flytachi\Winter\Kernel\Stereotype\Output\R;

if (!function_exists('wrImport')) {
    function wrImport(string $resourceName): void
    {
        R::import($resourceName);
    }
}

if (!function_exists('wrIsActiveLink')) {
    function wrIsActiveLink(
        string $link,
        string $classNameSuccess = 'active',
        string $classNameNone = ''
    ): string {
        return R::isActiveLink($link, $classNameSuccess, $classNameNone);
    }
}

if (!function_exists('wrContent')) {
    function wrContent(): void
    {
        R::content();
    }
}

if (!function_exists('wrData')) {
    function wrData(?string $valueKey = null): mixed
    {
        return R::getData($valueKey);
    }
}
