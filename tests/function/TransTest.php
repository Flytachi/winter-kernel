<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Func;

use Flytachi\Winter\K2\Localization\Locale;
use PHPUnit\Framework\TestCase;

final class TransTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/winter-kernel-trans-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
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

    public function test_trans_resolves_simple_key(): void
    {
        $this->writeLang('en', ['hello' => 'Hello']);
        Locale::setBasePath($this->tmpDir);
        Locale::set('en');

        self::assertSame('Hello', trans('hello'));
    }

    public function test_trans_returns_key_when_missing(): void
    {
        $this->writeLang('en', []);
        Locale::setBasePath($this->tmpDir);
        Locale::set('en');

        self::assertSame('missing.key', trans('missing.key'));
    }

    public function test_trans_with_default_null_params_does_not_throw(): void
    {
        // Regression: trans() declared ?array=null but Locale::translate()
        // is typed `array $params = []`. Without `?? []` this would TypeError.
        $this->writeLang('en', ['x' => 'plain']);
        Locale::setBasePath($this->tmpDir);
        Locale::set('en');

        self::assertSame('plain', trans('x'));        // implicit null
        self::assertSame('plain', trans('x', null));  // explicit null
    }

    public function test_trans_supports_sprintf_list_params(): void
    {
        $this->writeLang('en', ['greet' => 'Hello, %s!']);
        Locale::setBasePath($this->tmpDir);
        Locale::set('en');

        self::assertSame('Hello, Alice!', trans('greet', ['Alice']));
    }

    public function test_trans_supports_named_assoc_params(): void
    {
        $this->writeLang('en', ['greet' => 'Hello, :name!']);
        Locale::setBasePath($this->tmpDir);
        Locale::set('en');

        self::assertSame('Hello, Alice!', trans('greet', ['name' => 'Alice']));
    }
}
