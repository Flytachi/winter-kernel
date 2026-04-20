<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Localization;

/**
 * Parses Accept-Language header and picks the best available locale.
 *
 * Example:
 *   LanguageNegotiator::negotiate('ru-RU,ru;q=0.9,en;q=0.8', ['en','ru'], 'en') → 'ru'
 */
final class LanguageNegotiator
{
    private function __construct()
    {
    }

    /**
     * @param string   $acceptLanguage  Value of Accept-Language header
     * @param string[] $available       Locales the app supports (e.g. ['en','ru','kk'])
     * @param string   $default         Fallback locale
     */
    public static function negotiate(
        string $acceptLanguage,
        array $available,
        string $default,
    ): string {
        if (empty($acceptLanguage) || empty($available)) {
            return $default;
        }

        $priorities = [];

        foreach (explode(',', strtolower($acceptLanguage)) as $part) {
            $segments = explode(';', $part);
            $locale   = trim($segments[0]);
            $quality  = 1.0;

            if (isset($segments[1]) && str_starts_with(trim($segments[1]), 'q=')) {
                $quality = (float) substr(trim($segments[1]), 2);
            }

            $priorities[$locale] = $quality;
        }

        arsort($priorities);

        foreach (array_keys($priorities) as $locale) {
            if (in_array($locale, $available, true)) {
                return $locale;
            }
            // попробовать базовую часть: ru-RU → ru
            $base = strtok($locale, '-');
            if ($base !== false && in_array($base, $available, true)) {
                return $base;
            }
        }

        return $default;
    }
}
