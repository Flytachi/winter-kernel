<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Localization;

use Flytachi\Winter\Kernel\Localization\LocaleService;
use PHPUnit\Framework\TestCase;

final class LocaleServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/winter-kernel-locale-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function writeLang(string $lang, array $dictionary): void
    {
        file_put_contents(
            $this->tmpDir . "/$lang.php",
            '<?php return ' . var_export($dictionary, true) . ';'
        );
    }

    private function svc(string $lang): LocaleService
    {
        return new LocaleService($this->tmpDir, $lang);
    }

    // ── Basic lookup ──────────────────────────────────────────────────────────

    public function test_returns_translated_value_for_known_key(): void
    {
        $this->writeLang('en', ['hello' => 'world']);
        self::assertSame('world', $this->svc('en')->translate('hello'));
    }

    public function test_returns_key_when_not_found(): void
    {
        $this->writeLang('en', []);
        self::assertSame('missing.key', $this->svc('en')->translate('missing.key'));
    }

    public function test_returns_key_when_value_is_not_a_string(): void
    {
        $this->writeLang('en', ['k' => ['nested' => 'v']]); // 'k' itself maps to array
        self::assertSame('k', $this->svc('en')->translate('k'));
    }

    public function test_returns_key_when_value_is_empty_string(): void
    {
        $this->writeLang('en', ['k' => '']);
        self::assertSame('k', $this->svc('en')->translate('k'));
    }

    public function test_returns_key_when_lang_file_missing(): void
    {
        self::assertSame('any.key', $this->svc('zz')->translate('any.key'));
    }

    public function test_dot_notation_drills_into_nested_dictionary(): void
    {
        $this->writeLang('en', [
            'auth' => [
                'errors' => ['unauthorized' => 'Access denied'],
            ],
        ]);
        self::assertSame('Access denied', $this->svc('en')->translate('auth.errors.unauthorized'));
    }

    // ── sprintf params (list) ─────────────────────────────────────────────────

    public function test_list_params_use_sprintf(): void
    {
        $this->writeLang('en', ['greet' => 'Hello, %s!']);
        self::assertSame('Hello, Alice!', $this->svc('en')->translate('greet', ['Alice']));
    }

    public function test_list_params_support_indexed_sprintf(): void
    {
        $this->writeLang('en', ['fmt' => '%2$s/%1$s']);
        self::assertSame('b/a', $this->svc('en')->translate('fmt', ['a', 'b']));
    }

    // ── named placeholders (assoc) ────────────────────────────────────────────

    public function test_assoc_params_use_named_placeholders(): void
    {
        $this->writeLang('en', ['greet' => 'Hello, :name!']);
        self::assertSame(
            'Hello, Alice!',
            $this->svc('en')->translate('greet', ['name' => 'Alice'])
        );
    }

    public function test_assoc_params_substitute_multiple_named_placeholders(): void
    {
        $this->writeLang('en', ['range' => ':min..:max']);
        self::assertSame(
            '2..10',
            $this->svc('en')->translate('range', ['min' => 2, 'max' => 10])
        );
    }

    public function test_assoc_params_ignore_extra_keys(): void
    {
        $this->writeLang('en', ['x' => ':a']);
        self::assertSame(
            '1',
            $this->svc('en')->translate('x', ['a' => 1, 'unused' => 'extra'])
        );
    }

    public function test_assoc_params_leave_unknown_placeholders_untouched(): void
    {
        $this->writeLang('en', ['x' => ':a and :b']);
        self::assertSame(
            '1 and :b',
            $this->svc('en')->translate('x', ['a' => 1])
        );
    }

    public function test_assoc_params_stringify_objects_with_toString(): void
    {
        $stringable = new class {
            public function __toString(): string
            {
                return 'OBJ';
            }
        };
        $this->writeLang('en', ['x' => 'val=:v']);
        self::assertSame(
            'val=OBJ',
            $this->svc('en')->translate('x', ['v' => $stringable])
        );
    }

    public function test_assoc_params_stringify_null_to_empty(): void
    {
        $this->writeLang('en', ['x' => 'val=:v']);
        self::assertSame(
            'val=',
            $this->svc('en')->translate('x', ['v' => null])
        );
    }

    public function test_empty_params_returns_value_as_is(): void
    {
        $this->writeLang('en', ['x' => 'plain %s :v text']);
        self::assertSame('plain %s :v text', $this->svc('en')->translate('x'));
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function test_get_lang_returns_constructor_value(): void
    {
        self::assertSame('ru', $this->svc('ru')->getLang());
    }

    public function test_get_lang_path_returns_constructor_value(): void
    {
        self::assertSame($this->tmpDir, $this->svc('en')->getLangPath());
    }
}
