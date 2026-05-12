<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Localization;

use Flytachi\Winter\K2\Localization\Locale;
use Flytachi\Winter\K2\Localization\LocaleService;
use PHPUnit\Framework\TestCase;

final class LocaleTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/winter-kernel-locale-facade-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        // Reset facade state so subsequent tests don't see this dir.
        Locale::setBasePath('');
        Locale::setDefault('en');
    }

    private function writeLang(string $lang, array $dictionary): void
    {
        file_put_contents(
            $this->tmpDir . "/$lang.php",
            '<?php return ' . var_export($dictionary, true) . ';'
        );
    }

    public function test_translate_uses_set_locale(): void
    {
        $this->writeLang('ru', ['hello' => 'Привет']);
        Locale::setBasePath($this->tmpDir);
        Locale::set('ru');

        self::assertSame('Привет', Locale::translate('hello'));
    }

    public function test_t_is_alias_for_translate(): void
    {
        $this->writeLang('en', ['hello' => 'Hello']);
        Locale::setBasePath($this->tmpDir);
        Locale::set('en');

        self::assertSame(Locale::translate('hello'), Locale::t('hello'));
    }

    public function test_lang_returns_current_locale(): void
    {
        Locale::setBasePath($this->tmpDir);
        Locale::set('kk');
        self::assertSame('kk', Locale::lang());
    }

    public function test_set_overrides_current_locale(): void
    {
        $this->writeLang('en', ['hello' => 'Hello']);
        $this->writeLang('ru', ['hello' => 'Привет']);
        Locale::setBasePath($this->tmpDir);

        Locale::set('en');
        self::assertSame('Hello', Locale::translate('hello'));

        Locale::set('ru');
        self::assertSame('Привет', Locale::translate('hello'));
    }

    public function test_named_placeholders_resolve_through_facade(): void
    {
        $this->writeLang('en', ['greet' => 'Hi, :name!']);
        Locale::setBasePath($this->tmpDir);
        Locale::set('en');

        self::assertSame('Hi, Alice!', Locale::t('greet', ['name' => 'Alice']));
    }

    public function test_default_used_when_no_explicit_locale_set(): void
    {
        $this->writeLang('en', ['hello' => 'Hello']);
        Locale::setBasePath($this->tmpDir);
        Locale::setDefault('en');
        // No Locale::set() call → falls back to default
        self::assertSame('Hello', Locale::translate('hello'));
    }

    public function test_service_returns_locale_service_instance(): void
    {
        Locale::setBasePath($this->tmpDir);
        Locale::set('en');
        self::assertInstanceOf(LocaleService::class, Locale::service());
    }
}
