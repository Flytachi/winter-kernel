<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Localization;

use Flytachi\Winter\Base\Tool;

/**
 * Loads a translation dictionary from a PHP file and translates dot-notation keys.
 *
 * File format (e.g. lang/en.php):
 *   return [
 *       'auth' => [
 *           'unauthorized' => 'Access denied',
 *           'welcome'      => 'Welcome, %s!',
 *       ],
 *   ];
 *
 * Usage:
 *   $svc->translate('auth.unauthorized')                       → 'Access denied'
 *   $svc->translate('auth.welcome', ['Alice'])                 → 'Welcome, Alice!'   (sprintf, list params)
 *   $svc->translate('user.greet', ['name' => 'Alice'])         → ':name' placeholder via strtr (assoc params)
 *   $svc->translate('unknown.key')                             → 'unknown.key'
 */
class LocaleService
{
    private array $dictionary = [];
    private bool $loaded     = false;

    public function __construct(
        private readonly string $langPath,
        private readonly string $lang,
    ) {
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    public function getLangPath(): string
    {
        return $this->langPath;
    }

    /**
     * Translate a dot-notation key with optional params.
     *
     * Param style is auto-detected:
     *  - list (sequential keys)  → sprintf substitution: %s, %d, %1$s, …
     *  - associative (string keys) → :name placeholder substitution via strtr
     *
     * @param string $key e.g. 'error.not_found'
     * @param array<int|string,mixed> $params sprintf args (list) or :name map (assoc)
     */
    public function translate(string $key, array $params = []): string
    {
        $this->load();

        $value = Tool::arrayNestedValue($this->dictionary, explode('.', $key));

        if (!is_string($value) || $value === '') {
            return $key;
        }
        if ($params === []) {
            return $value;
        }
        if (array_is_list($params)) {
            return sprintf($value, ...$params);
        }

        $replace = [];
        foreach ($params as $name => $v) {
            $replace[':' . $name] = is_scalar($v) || $v === null
                ? (string) $v
                : (is_object($v) && method_exists($v, '__toString') ? (string) $v : '');
        }
        return strtr($value, $replace);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $file = rtrim($this->langPath, '/\\') . DIRECTORY_SEPARATOR . $this->lang . '.php';
        $this->dictionary = file_exists($file) ? (array) (include $file) : [];
        $this->loaded = true;
    }
}
