<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Localization;

final class LanguageNegotiator
{
    /**
     * Finds the best matching language based on the Accept-Language header and the available locales.
     *
     * @param string   $acceptLanguageHeader  The content of the 'Accept-Language' header.
     * @param string[] $availableLocales      An array of languages available in the application
     *                                        (e.g., from file names like ['en', 'ru']).
     * @param string   $defaultLocale         The default language to use.
     *
     * @return string The most appropriate language.
     */
    public static function negotiate(
        string $acceptLanguageHeader,
        array $availableLocales,
        string $defaultLocale
    ): string {
        if (empty($acceptLanguageHeader) || empty($availableLocales)) {
            return $defaultLocale;
        }

        $priorities = [];
        $accepted = explode(',', strtolower($acceptLanguageHeader));

        foreach ($accepted as $lang) {
            $parts = explode(';', $lang);
            $locale = trim($parts[0]);
            $quality = 1.0;

            if (isset($parts[1]) && str_starts_with(trim($parts[1]), 'q=')) {
                $quality = (float) substr(trim($parts[1]), 2);
            }

            $priorities[$locale] = $quality;
        }

        arsort($priorities);

        foreach (array_keys($priorities) as $locale) {
            if (in_array($locale, $availableLocales, true)) {
                return $locale;
            }
            $baseLocale = strtok($locale, '-');
            if (in_array($baseLocale, $availableLocales, true)) {
                return $baseLocale;
            }
        }

        return $defaultLocale;
    }
}
