<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Localization;

use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Localization\Locale;
use PHPUnit\Framework\TestCase;

/**
 * Dictionaries have a default home, `resources/lang`.
 *
 * Without one nothing translated and nothing said so: the base path stayed empty, the
 * dictionary was looked for at `/<lang>.php`, the file was never there, and every key came
 * back as itself. No exception, no log line — indistinguishable from a feature that does
 * not work. `ResponseView` had defaulted to `resources/views` all along; this brings the
 * other half of `resources/` in line.
 */
final class LocaleBasePathTest extends TestCase
{
    private string $dir;
    private ?string $originalResource = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wk_locale_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/lang', 0777, true);
        file_put_contents(
            $this->dir . '/lang/en.php',
            "<?php return ['auth' => ['welcome' => 'Welcome, %s!']];"
        );
        file_put_contents(
            $this->dir . '/lang/ru.php',
            "<?php return ['auth' => ['welcome' => 'Добро пожаловать, %s!']];"
        );

        if (isset(Kernel::$pathResource)) {
            $this->originalResource = Kernel::$pathResource;
        }
        Kernel::$pathResource = $this->dir;
        self::forgetBasePath();
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
        self::forgetBasePath();
    }

    /** Resets the memoised path so each test starts from "nothing configured". */
    private static function forgetBasePath(): void
    {
        new \ReflectionProperty(Locale::class, 'basePath')->setValue(null, null);
        new \ReflectionProperty(Locale::class, 'static')->setValue(null, null);
    }

    public function test_dictionaries_default_to_resources_lang(): void
    {
        self::assertSame($this->dir . DIRECTORY_SEPARATOR . 'lang', Locale::basePath());
    }

    /** The point of the default: a dictionary on disk translates with no configuration. */
    public function test_a_dictionary_translates_without_being_configured(): void
    {
        Locale::set('ru');

        self::assertSame('Добро пожаловать, Жасур!', Locale::t('auth.welcome', ['Жасур']));
    }

    public function test_an_explicit_path_still_wins(): void
    {
        Locale::setBasePath($this->dir . '/lang');

        self::assertSame($this->dir . '/lang', Locale::basePath());
        Locale::set('en');
        self::assertSame('Welcome, Alice!', Locale::t('auth.welcome', ['Alice']));
    }

    /** A missing key is still returned as itself — that behaviour is unchanged. */
    public function test_an_unknown_key_is_returned_as_written(): void
    {
        Locale::set('en');

        self::assertSame('nope.missing', Locale::t('nope.missing'));
    }

    /**
     * Reading the default must not fatal before `Kernel::init()` settles the paths:
     * `Kernel::$pathResource` is a typed static, so touching it early is an `Error`, not a
     * null. A CLI verb that never boots the kernel may still translate.
     */
    public function test_translating_before_the_kernel_is_initialised_does_not_fatal(): void
    {
        // A typed static cannot be returned to its uninitialised state from inside the
        // process, so the condition is exercised where it actually occurs: a fresh one
        // that never called Kernel::init().
        $script = sprintf(
            'require %s; echo Flytachi\Winter\Kernel\Localization\Locale::basePath() === "" ? "empty" : "resolved";',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
        );

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, 'reading the default must not fatal: ' . implode("\n", $output));
        self::assertContains('empty', $output, 'with no paths settled there is nowhere to look');
    }
}
