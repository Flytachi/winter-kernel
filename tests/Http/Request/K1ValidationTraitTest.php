<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request;

use Flytachi\Winter\K2\Http\Request\K1ValidationTrait;
use Flytachi\Winter\K2\Http\Request\RequestException;
use Flytachi\Winter\K2\Localization\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// ── Fixture using the legacy trait ───────────────────────────────────────────

final class K1Fixture
{
    use K1ValidationTrait {
        validate as public publicValidate;
    }

    public function __construct(
        public mixed $name = null,
        public mixed $age = null,
        public mixed $email = null,
        public mixed $items = null,
        public mixed $nested = null,
    ) {
    }

    /** Expose private get() for direct path-traversal tests. */
    public function publicGet(string $field): mixed
    {
        return $this->{'get'}($field);
    }
}

final class K1ValidationTraitTest extends TestCase
{
    // ── single-rule happy paths ──────────────────────────────────────────────

    #[DataProvider('passingRules')]
    public function test_rule_passes(string $rule, mixed $value): void
    {
        $f = new K1Fixture(name: $value);
        $f->publicValidate('name', [$rule]);
        // No exception thrown = success
        self::assertSame($value, $f->name);
    }

    public static function passingRules(): iterable
    {
        yield 'boolean true'    => ['boolean', true];
        yield 'bool alias'      => ['bool', false];
        yield 'numeric int'     => ['numeric', 42];
        yield 'numeric string'  => ['numeric', '3.14'];
        yield 'number alias'    => ['number', 7];
        yield 'string'          => ['string', 'hello'];
        yield 'str alias'       => ['str', ''];
        yield 'array'           => ['array', []];
        yield 'list alias'      => ['list', [1, 2]];
        yield 'positive'        => ['positive', 5];
        yield 'id alias'        => ['id', 1];
        yield 'negative'        => ['negative', -3];
        yield 'email'           => ['email', 'a@b.co'];
        yield 'url'             => ['url', 'https://example.com'];
        yield 'ip v4'           => ['ip', '127.0.0.1'];
        yield 'ip v6'           => ['ip', '::1'];
        yield 'ipv4'            => ['ipv4', '127.0.0.1'];
        yield 'ip4 alias'       => ['ip4', '8.8.8.8'];
        yield 'ipv6'            => ['ipv6', '::1'];
        yield 'ip6 alias'       => ['ip6', 'fe80::1'];
        yield 'uuid'            => ['uuid', '550e8400-e29b-41d4-a716-446655440000'];
        yield 'msisdn'          => ['msisdn', '+79001234567'];
        yield 'phone'           => ['phone', '+7 (900) 123-45-67'];
        yield 'length min only' => ['length:3', 'abc'];
        yield 'length min/max'  => ['length:2,5', 'abcd'];
        yield 'len alias'       => ['len:1,3', 'ok'];
        yield 'range'           => ['range:1,10', 5];
        yield 'rg alias'        => ['rg:0,100', '50'];
        yield 'in'              => ['in:a,b,c', 'b'];
        yield 'datetime'        => ['datetime', '2024-01-31 14:30:00'];
        yield 'datetime fmt'    => ['datetime:Y-m-d', '2024-01-31'];
        yield 'date alias'      => ['date:Y-m-d', '2024-01-31'];
        yield 'time alias'      => ['time:H:i', '14:30'];
    }

    // ── single-rule failing paths ────────────────────────────────────────────

    #[DataProvider('failingRules')]
    public function test_rule_fails(string $rule, mixed $value, string $expectedSubstring): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage($expectedSubstring);
        (new K1Fixture(name: $value))->publicValidate('name', [$rule]);
    }

    public static function failingRules(): iterable
    {
        yield 'boolean'  => ['boolean',   'not-bool',  "must be boolean"];
        yield 'numeric'  => ['numeric',   'abc',       "must be numeric"];
        yield 'string'   => ['string',    123,         "must be a string"];
        yield 'array'    => ['array',     'x',         "must be an array"];
        yield 'positive' => ['positive',  -1,          "must be positive"];
        yield 'positive zero' => ['positive', 0,       "must be positive"];
        yield 'negative' => ['negative',  1,           "must be negative"];
        yield 'email'    => ['email',     'bad',       "must be a valid email"];
        yield 'url'      => ['url',       'bad',       "must be a valid URL"];
        yield 'ip'       => ['ip',        'bad',       "must be a valid IP"];
        yield 'ipv4 v6'  => ['ipv4',      '::1',       "must be a valid IPv4"];
        yield 'ipv6 v4'  => ['ipv6',      '127.0.0.1', "must be a valid IPv6"];
        yield 'uuid'     => ['uuid',      'not-uuid',  "must be a valid UUID"];
        yield 'msisdn'   => ['msisdn',    '+0',        "must be a valid MSISDN"];
        yield 'phone'    => ['phone',     'abc',       "must be a valid phone number"];
        yield 'length'   => ['length:2,4', 'a',        "length must be between 2 and 4"];
        yield 'length over' => ['length:2,4', 'abcdef', "length must be between 2 and 4"];
        yield 'range non-numeric' => ['range:0,10', 'abc', "must be numeric for 'range' rule"];
        yield 'range over' => ['range:1,5', 6,        "must be in range 1 – 5"];
        yield 'in'       => ['in:a,b,c',   'd',        "must be one of: a, b, c"];
        yield 'datetime' => ['datetime',   'not-dt',   "must match format Y-m-d H:i:s"];
        yield 'datetime fmt' => ['datetime:Y-m-d', '31.01.2024', "must match format Y-m-d"];
        yield 'datetime non-string' => ['datetime', 12345,  "must be a datetime string"];
    }

    // ── unknown rule ────────────────────────────────────────────────────────

    public function test_unknown_rule_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Unknown validation rule 'whatever'");
        (new K1Fixture(name: 'x'))->publicValidate('name', ['whatever']);
    }

    // ── required / optional ─────────────────────────────────────────────────

    public function test_optional_null_is_skipped(): void
    {
        // No exception even though 'numeric' would normally fail on null
        (new K1Fixture(age: null))->publicValidate('age', ['numeric'], required: false);
        $this->expectNotToPerformAssertions();
    }

    public function test_required_null_on_existing_property_runs_rules(): void
    {
        // Property exists but value is null → runs rules; numeric() fails on null
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be numeric");
        (new K1Fixture(age: null))->publicValidate('age', ['numeric']);
    }

    // ── callable rules ──────────────────────────────────────────────────────

    public function test_callable_rule_passes(): void
    {
        $f = new K1Fixture(age: 18);
        $f->publicValidate('age', [fn($v) => $v >= 18]);
        self::assertSame(18, $f->age);
    }

    public function test_callable_rule_fails(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Field 'age' failed custom validation");
        (new K1Fixture(age: 10))->publicValidate('age', [fn($v) => $v >= 18]);
    }

    // ── multiple rules in a chain ───────────────────────────────────────────

    public function test_chained_rules_all_pass(): void
    {
        (new K1Fixture(age: 25))->publicValidate('age', ['numeric', 'positive', 'range:18,99']);
        $this->expectNotToPerformAssertions();
    }

    public function test_chained_rules_first_failure_throws(): void
    {
        // 'numeric' passes on '5'; 'range:1,3' should fail
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be in range 1 – 3");
        (new K1Fixture(age: '5'))->publicValidate('age', ['numeric', 'range:1,3']);
    }

    // ── deep get() via dot path ─────────────────────────────────────────────

    public function test_get_walks_array_by_dots(): void
    {
        $f = new K1Fixture(nested: ['user' => ['city' => 'NYC']]);
        self::assertSame('NYC', $f->publicGet('nested.user.city'));
    }

    public function test_get_returns_null_for_missing_path(): void
    {
        $f = new K1Fixture(nested: ['user' => []]);
        self::assertNull($f->publicGet('nested.user.city'));
    }

    public function test_get_returns_null_for_missing_property(): void
    {
        self::assertNull((new K1Fixture())->publicGet('nope'));
    }

    // ── wildcard (*) expansion ───────────────────────────────────────────────

    public function test_wildcard_validates_each_element(): void
    {
        $f = new K1Fixture(items: [
            ['id' => 1, 'isResponsible' => true],
            ['id' => 2, 'isResponsible' => false],
        ]);
        $f->publicValidate('items.*', ['array'])
          ->publicValidate('items.*.id', ['number'])
          ->publicValidate('items.*.isResponsible', ['bool'], required: false);
        $this->expectNotToPerformAssertions();
    }

    public function test_wildcard_reports_failing_element_with_resolved_path(): void
    {
        $f = new K1Fixture(items: [
            ['id' => 1],
            ['id' => 'oops'], // second element is not numeric
        ]);
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Field 'items.1.id' must be numeric");
        $f->publicValidate('items.*.id', ['number']);
    }

    public function test_wildcard_optional_skips_missing_element_key(): void
    {
        // No 'isResponsible' on either element → optional rule must not fire
        $f = new K1Fixture(items: [['id' => 1], ['id' => 2]]);
        $f->publicValidate('items.*.isResponsible', ['bool'], required: false);
        $this->expectNotToPerformAssertions();
    }

    public function test_wildcard_required_missing_element_key_fails(): void
    {
        $f = new K1Fixture(items: [['id' => 1], ['name' => 'x']]); // 2nd lacks id
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Required field 'items.1.id' not found");
        $f->publicValidate('items.*.id', ['number']);
    }

    public function test_wildcard_over_missing_collection_is_noop(): void
    {
        // 'items' is null → zero elements → nothing validated, even when required
        (new K1Fixture())->publicValidate('items.*.id', ['number']);
        $this->expectNotToPerformAssertions();
    }

    public function test_wildcard_over_empty_collection_is_noop(): void
    {
        (new K1Fixture(items: []))->publicValidate('items.*', ['array']);
        $this->expectNotToPerformAssertions();
    }

    // ── custom message override ─────────────────────────────────────────────

    public function test_custom_message_replaces_default(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('CUSTOM_TEXT');
        (new K1Fixture(age: 'abc'))->publicValidate('age', ['numeric'], message: 'CUSTOM_TEXT');
    }

    public function test_custom_message_applies_to_callable_failure(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('age too small');
        (new K1Fixture(age: 1))->publicValidate(
            'age',
            [fn($v) => $v > 100],
            message: 'age too small',
        );
    }

    // ── i18n {key} resolution ───────────────────────────────────────────────

    public function test_brace_marker_resolves_through_locale(): void
    {
        $tmpDir = $this->setUpLangDir([
            'k1' => ['must_be_numeric' => 'Поле «:field» должно быть числом'],
        ]);
        try {
            try {
                (new K1Fixture(age: 'abc'))->publicValidate(
                    'age',
                    ['numeric'],
                    message: '{k1.must_be_numeric}',
                );
                $this->fail('Expected RequestException');
            } catch (RequestException $e) {
                self::assertSame('Поле «age» должно быть числом', $e->getMessage());
            }
        } finally {
            $this->tearDownLangDir($tmpDir);
        }
    }

    public function test_brace_marker_with_unknown_key_falls_back_to_key(): void
    {
        $tmpDir = $this->setUpLangDir([]);
        try {
            try {
                (new K1Fixture(age: 'abc'))->publicValidate(
                    'age',
                    ['numeric'],
                    message: '{missing.key}',
                );
                $this->fail('Expected RequestException');
            } catch (RequestException $e) {
                // LocaleService returns the key when the path does not resolve
                self::assertSame('missing.key', $e->getMessage());
            }
        } finally {
            $this->tearDownLangDir($tmpDir);
        }
    }

    private function setUpLangDir(array $dictionary): string
    {
        $tmpDir = sys_get_temp_dir() . '/winter-k1-i18n-' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        file_put_contents(
            $tmpDir . '/en.php',
            '<?php return ' . var_export($dictionary, true) . ';'
        );
        Locale::setBasePath($tmpDir);
        Locale::set('en');
        return $tmpDir;
    }

    private function tearDownLangDir(string $tmpDir): void
    {
        @unlink($tmpDir . '/en.php');
        @rmdir($tmpDir);
        Locale::setBasePath('');
    }

    // ── chainable return value ──────────────────────────────────────────────

    public function test_validate_returns_self_for_chaining(): void
    {
        $f = new K1Fixture(name: 'ok', age: 20);
        $result = $f->publicValidate('name', ['string'])
                    ->publicValidate('age', ['numeric', 'positive']);
        self::assertSame($f, $result);
    }
}
