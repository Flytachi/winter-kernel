<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Localization;

use Flytachi\Winter\Kernel\Http\Cookie\Cookie;
use Flytachi\Winter\Kernel\Http\Header;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Localization\Locale;
use Flytachi\Winter\Kernel\Tests\Http\Fixtures\OriginProbeRequest;
use Flytachi\Winter\Kernel\Tests\Route\Fixtures\FakeResponse;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * An explicit language choice beats what the browser prefers.
 *
 * `Accept-Language` describes a preference — usually the operating system's, often
 * nothing the visitor ever chose. A cookie records a decision: someone clicked the
 * switcher. So the cookie is read first, and negotiation is the fallback rather than the
 * rule.
 *
 * The cookie value is client-controlled and ends up in the dictionary path, so the other
 * half of these tests is about the values that must not be honoured.
 */
final class LocaleCookieTest extends TestCase
{
    private string $dir = '';
    private ?string $originalResource = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wk_locale_cookie_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/lang', 0777, true);
        foreach (['en' => 'Hello', 'ru' => 'Привет', 'kk' => 'Сәлем'] as $lang => $word) {
            file_put_contents($this->dir . "/lang/{$lang}.php", "<?php return ['greeting' => '{$word}'];");
        }

        if (isset(Kernel::$pathResource)) {
            $this->originalResource = Kernel::$pathResource;
        }
        Kernel::$pathResource = $this->dir;
        self::reset();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/lang/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir . '/lang');
        @rmdir($this->dir);

        if ($this->originalResource !== null) {
            Kernel::$pathResource = $this->originalResource;
        }
        self::reset();
        Locale::setCookieName('locale');
        Cookie::clear();
    }

    /**
     * Back to "nothing has happened yet".
     *
     * Header keeps its bag in a static for FPM mode, so without this a previous test's
     * `Accept-Language` would still be answering questions in the next one.
     */
    private static function reset(): void
    {
        new ReflectionProperty(Locale::class, 'basePath')->setValue(null, null);
        new ReflectionProperty(Locale::class, 'static')->setValue(null, null);
        new ReflectionProperty(Header::class, 'bag')->setValue(null, []);
        new ReflectionProperty(Header::class, 'origin')->setValue(null, []);
        new ReflectionProperty(Header::class, 'request')->setValue(null, null);
        Cookie::clear();
    }

    /** Boots a request the way the router does: headers, then cookies, then the locale. */
    private function request(string $cookies = '', string $acceptLanguage = ''): void
    {
        $headers = [];
        if ($cookies !== '') {
            $headers['Cookie'] = $cookies;
        }
        if ($acceptLanguage !== '') {
            $headers['Accept-Language'] = $acceptLanguage;
        }

        $request = new OriginProbeRequest(headers: $headers);
        Header::init($request);
        Cookie::init($request, new FakeResponse());
        Locale::initFromRequest();
    }

    // ── The choice wins ───────────────────────────────────────────────────────

    public function test_the_cookie_decides_the_language(): void
    {
        $this->request(cookies: 'locale=ru');

        self::assertSame('ru', Locale::lang());
        self::assertSame('Привет', Locale::t('greeting'));
    }

    public function test_the_cookie_beats_accept_language(): void
    {
        $this->request(cookies: 'locale=kk', acceptLanguage: 'ru-RU,ru;q=0.9,en;q=0.8');

        self::assertSame('kk', Locale::lang(), 'the visitor clicked the switcher; the OS did not');
    }

    public function test_without_a_cookie_accept_language_still_decides(): void
    {
        $this->request(acceptLanguage: 'ru-RU,ru;q=0.9,en;q=0.8');

        self::assertSame('ru', Locale::lang());
    }

    public function test_with_neither_the_default_is_used(): void
    {
        $this->request();

        self::assertSame('en', Locale::lang());
    }

    // ── Values that must not be honoured ──────────────────────────────────────

    /**
     * The value becomes `<basePath>/<lang>.php`. Accepting it as a string would let a
     * visitor point the dictionary loader anywhere on disk.
     */
    public function test_a_traversal_attempt_is_ignored(): void
    {
        $this->request(cookies: 'locale=' . rawurlencode('../../../../etc/passwd'), acceptLanguage: 'ru');

        self::assertSame('ru', Locale::lang(), 'it falls through to negotiation, as an unknown value');
    }

    public function test_a_language_without_a_dictionary_is_ignored(): void
    {
        $this->request(cookies: 'locale=de', acceptLanguage: 'ru');

        self::assertSame('ru', Locale::lang());
    }

    public function test_an_empty_cookie_is_ignored(): void
    {
        $this->request(cookies: 'locale=', acceptLanguage: 'ru');

        self::assertSame('ru', Locale::lang());
    }

    // ── Configuration ─────────────────────────────────────────────────────────

    public function test_the_cookie_name_can_be_changed(): void
    {
        Locale::setCookieName('lang');

        $this->request(cookies: 'lang=ru; locale=kk');

        self::assertSame('ru', Locale::lang(), 'only the configured name is read');
    }

    /** For an application where the URL or the account decides, and a stale cookie would fight it. */
    public function test_the_cookie_check_can_be_turned_off(): void
    {
        Locale::setCookieName(null);

        $this->request(cookies: 'locale=kk', acceptLanguage: 'ru');

        self::assertSame('ru', Locale::lang());
    }

    /** Nothing in this path may require a request: CLI translates too. */
    public function test_no_request_at_all_is_not_an_error(): void
    {
        Cookie::clear();
        Locale::initFromRequest();

        self::assertSame('en', Locale::lang());
    }
}
