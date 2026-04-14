<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Localization;

use Flytachi\Winter\Base\Tool;

class LocaleService
{
    private string $langPath;
    private string $currentLang;
    private array $dictionary = [];
    private bool $isLoaded = false;

    public function __construct(string $langPath, string $currentLang)
    {
        $this->langPath = rtrim($langPath, '/');
        $this->currentLang = $currentLang;
    }

    public function getLang(): string
    {
        return $this->currentLang;
    }

    public function getPath(): string
    {
        return $this->langPath;
    }

    /**
     * Translates a given key using the loaded dictionary.
     *
     * This method retrieves the translation string from the dictionary using a dot-separated key.
     * If parameters are provided, they will be inserted into the translated string using `sprintf()`.
     * If the key is not found, it returns the key as is.
     *
     *  Example:
     *  ```
     *  // Dictionary file (en.php):
     *  return [
     *      'error' => [
     *          'not_found' => 'Page not found',
     *          'server' => 'Server error: %s',
     *      ],
     *      'user' => [
     *          'welcome' => 'Welcome, %s!',
     *      ],
     *  ];
     *
     *  // Usage:
     *  Locale::translate('error.not_found');        // result: "Page not found"
     *  Locale::translate('error.server', ['500']);  // result: "Server error: 500"
     *  Locale::translate('user.welcome', ['John']); // result: "Welcome, John!"
     *  Locale::translate('unknown.key');            // result: "unknown.key"
     *  ```
     * @param string $key The translation key, using dot notation for nested values.
     * @param array|null $params Optional parameters to replace placeholders in the translation string.
     *
     * @return string The translated string or the key if no translation is found.
     */
    public function translate(string $key, ?array $params = null): string
    {
        $this->loadDictionary();
        $value = Tool::arrayNestedValue($this->dictionary, explode('.', $key));

        if (is_string($value) && !empty($value)) {
            return empty($params) ? $value : sprintf($value, ...$params);
        }
        return $key;
    }

    private function loadDictionary(): void
    {
        if ($this->isLoaded) {
            return;
        }
        $path = "{$this->langPath}/{$this->currentLang}.php";
        $this->dictionary = file_exists($path) ? (include $path) : [];
        $this->isLoaded = true;
    }
}
