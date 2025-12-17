<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Localization;

use Flytachi\Winter\Base\Header;
use Flytachi\Winter\Kernel\Kernel;

final class Locale
{
    private static ?LocaleService $serviceInstance = null;

    private function __construct()
    {
    }

    private static function init(): void
    {
        if (self::$serviceInstance === null) {
            $langPath = Kernel::$pathRoot . '/lang';

            $available = self::getAvailableLanguages($langPath);
            $acceptHeader = Header::getHeader('Accept-Language') ?: 'en';

            $bestLang = LanguageNegotiator::negotiate($acceptHeader, $available, 'en');

            self::$serviceInstance = new LocaleService($langPath, $bestLang);
        }
    }

    public static function getService(): LocaleService
    {
        self::init();
        return self::$serviceInstance;
    }

    /**
     * @param string $key
     * @param array|null $params
     * @return string
     * @see LocaleService::translate()
     */
    public static function translate(string $key, ?array $params = null): string
    {
        return self::getService()->translate($key, $params);
    }

    public static function getLang(): string
    {
        return self::getService()->getLang();
    }

    public static function getPath(): string
    {
        return self::getService()->getPath();
    }

    public static function setLang(string $lang): void
    {
        $currentPath = self::$serviceInstance ? self::getPath() : Extra::$pathRoot . '/lang';
        self::$serviceInstance = new LocaleService($currentPath, $lang);
    }

    public static function setPath(string $path): void
    {
        $currentLang = self::$serviceInstance ? self::getLang() : 'en';
        self::$serviceInstance = new LocaleService($path, $currentLang);
    }

    private static function getAvailableLanguages(string $langPath): array
    {
        $files = glob("{$langPath}/*.php");
        return $files ? array_map(fn($f) => basename($f, '.php'), $files) : [];
    }

    public static function reset(): void
    {
        self::$serviceInstance = null;
    }
}
