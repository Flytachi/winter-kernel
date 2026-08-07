<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Localization;

use Flytachi\Winter\Kernel\Http\Header;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Base\Runtime;

/**
 * Static facade for per-request localization.
 *
 * Coroutine-safe: each Swoole coroutine gets its own LocaleService instance.
 * In FPM mode, a single static instance is used (one request per process).
 *
 * Dictionaries live in `resources/lang/<lang>.php` and need no configuration; both the
 * directory and the fallback language can be changed at boot:
 *   Locale::setBasePath(Kernel::$pathRoot . '/translations');
 *   Locale::setDefault('ru');
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
    /** Directory under `resources/` holding `<lang>.php` dictionaries. */
    private const string DEFAULT_DIR = 'lang';

    private static ?string $basePath = null;
    private static string $default  = 'en';

    /** FPM fallback — used when not inside a Swoole coroutine. */
    private static ?LocaleService $static = null;

    private function __construct()
    {
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    /**
     * Where the `<lang>.php` dictionaries live. Say nothing and it is
     * `resources/lang`, beside `resources/views` — the same shape
     * {@see \Flytachi\Winter\Kernel\Http\Response\ResponseView} uses.
     *
     * ```
     * Locale::setBasePath(Kernel::$pathRoot . '/translations');
     * ```
     *
     * Call it before the first translation — from `configure()` on the application class,
     * which runs right after {@see Kernel::init()} has settled the paths. A later call
     * still takes effect, but anything already translated used the old directory.
     *
     * The default exists because its absence read as a broken feature rather than a
     * missing setting: with no base path the dictionary was looked up at `/<lang>.php`,
     * the file was never there, and every key came back as itself — no exception, no log
     * line, nothing to search for.
     */
    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/\\');
        self::$static   = null;
    }

    /**
     * The directory in force: the configured one, or `resources/lang`.
     *
     * Resolved on read rather than at boot, because {@see Kernel::init()} settles
     * `$pathResource` after this class is loaded.
     */
    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }
        // `Kernel::$pathResource` is a typed static: reading it before Kernel::init() is a
        // fatal, not a null. Translating that early is legitimate (a CLI verb that never
        // boots the kernel), so answer "nowhere" and stay unmemoised — the next call, once
        // the paths are settled, resolves properly.
        if (!isset(Kernel::$pathResource)) {
            return '';
        }

        return self::$basePath = rtrim(Kernel::$pathResource, '/\\')
            . DIRECTORY_SEPARATOR . self::DEFAULT_DIR;
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

        self::store(new LocaleService(self::basePath(), $lang));
    }

    /**
     * Override locale for the current request.
     */
    public static function set(string $lang): void
    {
        self::store(new LocaleService(self::basePath(), $lang));
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
        $svc = new LocaleService(self::basePath(), self::$default);
        self::store($svc);
        return $svc;
    }

    private static function isSwoole(): bool
    {
        return Runtime::isSwooleCoroutine();
    }

    /** Scan lang/ directory for available locale files. */
    private static function scanAvailable(): array
    {
        $base = self::basePath();
        if ($base === '') {
            return [];
        }
        $files = glob($base . DIRECTORY_SEPARATOR . '*.php');
        return $files ? array_map(fn($f) => basename($f, '.php'), $files) : [];
    }
}
