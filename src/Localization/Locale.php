<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Localization;

use Flytachi\Winter\K2\Http\Header;

/**
 * Static facade for per-request localization.
 *
 * Coroutine-safe: each Swoole coroutine gets its own LocaleService instance.
 * In FPM mode, a single static instance is used (one request per process).
 *
 * Bootstrap (once, before Router::handle):
 *   Locale::setBasePath(__DIR__ . '/lang');
 *   Locale::setDefault('en');
 *
 * Per-request auto-init (called inside Router::handle via Header::init):
 *   Locale::initFromRequest();  // reads Accept-Language, picks best locale
 *
 * Usage anywhere in the app:
 *   Locale::translate('auth.unauthorized')         → 'Access denied'
 *   Locale::translate('auth.welcome', ['Alice'])   → 'Welcome, Alice!'
 *   Locale::lang()                                 → 'ru'
 *   Locale::set('kk')                              → override for current request
 */
final class Locale
{
    private static string  $basePath = '';
    private static string  $default  = 'en';

    /** FPM fallback — used when not inside a Swoole coroutine. */
    private static ?LocaleService $static = null;

    private function __construct() {}

    // ── Configuration ─────────────────────────────────────────────────────────

    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/\\');
        self::$static   = null;
    }

    public static function setDefault(string $locale): void
    {
        self::$default = $locale;
    }

    // ── Per-request init ──────────────────────────────────────────────────────

    /**
     * Auto-detect locale from Accept-Language header.
     * Call once per request (e.g. in Router::handle after Header::init).
     */
    public static function initFromRequest(): void
    {
        $accept    = Header::get('Accept-Language') ?? '';
        $available = self::scanAvailable();
        $lang      = LanguageNegotiator::negotiate($accept, $available, self::$default);

        self::store(new LocaleService(self::$basePath, $lang));
    }

    /**
     * Override locale for the current request.
     */
    public static function set(string $lang): void
    {
        self::store(new LocaleService(self::$basePath, $lang));
    }

    // ── API ───────────────────────────────────────────────────────────────────

    public static function translate(string $key, array $params = []): string
    {
        return self::service()->translate($key, $params);
    }

    /** Shorthand alias. */
    public static function t(string $key, array $params = []): string
    {
        return self::service()->translate($key, $params);
    }

    public static function lang(): string
    {
        return self::service()->getLang();
    }

    public static function service(): LocaleService
    {
        return self::fetch() ?? self::makeDefault();
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function store(LocaleService $svc): void
    {
        if (self::isSwoole()) {
            \Swoole\Coroutine::getContext()[self::class] = $svc;
        } else {
            self::$static = $svc;
        }
    }

    private static function fetch(): ?LocaleService
    {
        if (self::isSwoole()) {
            return \Swoole\Coroutine::getContext()[self::class] ?? null;
        }
        return self::$static;
    }

    private static function makeDefault(): LocaleService
    {
        $svc = new LocaleService(self::$basePath, self::$default);
        self::store($svc);
        return $svc;
    }

    private static function isSwoole(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false)
            && \Swoole\Coroutine::getCid() >= 0;
    }

    /** Scan lang/ directory for available locale files. */
    private static function scanAvailable(): array
    {
        if (self::$basePath === '') {
            return [];
        }
        $files = glob(self::$basePath . DIRECTORY_SEPARATOR . '*.php');
        return $files ? array_map(fn($f) => basename($f, '.php'), $files) : [];
    }
}
