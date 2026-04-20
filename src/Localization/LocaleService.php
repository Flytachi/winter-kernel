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
 *   $svc->translate('auth.unauthorized')          → 'Access denied'
 *   $svc->translate('auth.welcome', ['Alice'])    → 'Welcome, Alice!'
 *   $svc->translate('unknown.key')                → 'unknown.key'
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
     * Translate a dot-notation key with optional sprintf params.
     *
     * @param string       $key    e.g. 'error.not_found'
     * @param list<mixed>  $params Optional sprintf arguments
     */
    public function translate(string $key, array $params = []): string
    {
        $this->load();

        $value = Tool::arrayNestedValue($this->dictionary, explode('.', $key));

        if (!is_string($value) || $value === '') {
            return $key;
        }

        return $params === [] ? $value : sprintf($value, ...$params);
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
