<?php

declare(strict_types=1);

use \Flytachi\Winter\Kernel\Localization\Locale;

if (!function_exists('trans')) {
    /**
     * Translates a given key using the loaded dictionary.
     *
     * This method retrieves the translation string from the dictionary using a dot-separated key.
     * If parameters are provided, they will be inserted into the translated string using `sprintf()`.
     * If the key is not found, it returns the key as is.
     *
     * @param string $key The translation key, using dot notation for nested values.
     * @param array|null $params Optional parameters to replace placeholders in the translation string.
     *
     * @return string The translated string or the key if no translation is found.
     */
    function trans(string $key, ?array $params = null): string
    {
        return Locale::translate($key, $params);
    }
}
