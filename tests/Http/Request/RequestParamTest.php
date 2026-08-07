<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\ParameterResolver;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestParam;
use Flytachi\Winter\Kernel\Http\Request\RequestException;
use Flytachi\Winter\Kernel\Http\Request\Validation\Positive;
use Flytachi\Winter\Kernel\Http\Request\Validation\Size;
use Flytachi\Winter\Kernel\Http\Request\Validation\ValidationException;
use Flytachi\Winter\Kernel\Localization\Locale;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Fixture enums ─────────────────────────────────────────────────────────────

enum ReqParamStatus: string
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';
}

enum ReqParamCode: int
{
    case OK   = 1;
    case FAIL = 0;
}

// ── Fixture controller ────────────────────────────────────────────────────────

class RequestParamFixture
{
    public function byNameInt(#[RequestParam] int $page): void {}
    public function byNameString(#[RequestParam] string $search): void {}
    public function byNameFloat(#[RequestParam] float $ratio): void {}
    public function byNameBool(#[RequestParam] bool $active): void {}
    public function byNameArray(#[RequestParam] array $ids): void {}
    public function customName(#[RequestParam('page_size')] int $pageSize): void {}
    public function nullable(#[RequestParam] ?int $page): void {}
    public function withDefault(#[RequestParam] int $page = 1): void {}
    public function nullableDate(#[RequestParam] ?\DateTimeImmutable $from): void {}
    public function nullableArray(#[RequestParam] ?array $ids): void {}
    public function dateTimeMutable(#[RequestParam] \DateTime $date): void {}
    public function bcNumber(#[RequestParam] \BcMath\Number $value): void {}
    public function decimal(#[RequestParam] \Decimal\Decimal $value): void {}
    public function enumString(#[RequestParam] ReqParamStatus $status): void {}
    public function enumInt(#[RequestParam] ReqParamCode $code): void {}
    public function dateTime(#[RequestParam] \DateTimeImmutable $date): void {}
    public function camelParam(#[RequestParam] int $pageSize): void {}
    public function constrainedId(#[RequestParam, Positive] int $id): void {}
    public function constrainedIdWithCustomMessage(
        #[RequestParam, Positive(message: 'must be > 0')] int $id,
    ): void {}
    public function constrainedIdWithI18nMessage(
        #[RequestParam, Positive(message: '{order.id_must_be_positive}')] int $id,
    ): void {}
    public function constrainedNameWithI18nMessage(
        #[RequestParam, Size(min: 0, max: 3, message: '{order.name_too_long}')] string $name,
    ): void {}
}

// ─────────────────────────────────────────────────────────────────────────────

class RequestParamTest extends TestCase
{
    private HttpRequest  $request;
    private HttpResponse $response;

    protected function setUp(): void
    {
        $this->request  = $this->createStub(HttpRequest::class);
        $this->response = $this->createStub(HttpResponse::class);
    }

    private function resolve(string $method, array $queryParams): array
    {
        $this->request
            ->method('getQueryParams')
            ->willReturn($queryParams);

        return ParameterResolver::resolve(
            new ReflectionMethod(RequestParamFixture::class, $method),
            $this->request,
            $this->response,
            [],
        );
    }

    // ── int ───────────────────────────────────────────────────────────────────

    public function test_int(): void
    {
        [$page] = $this->resolve('byNameInt', ['page' => '3']);
        $this->assertSame(3, $page);
    }

    public function test_int_invalid_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Query parameter 'page' must be an integer, got 'abc'");
        $this->resolve('byNameInt', ['page' => 'abc']);
    }

    public function test_int_missing_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Required query parameter 'page' is missing");
        $this->resolve('byNameInt', []);
    }

    // ── string ────────────────────────────────────────────────────────────────

    public function test_string(): void
    {
        [$search] = $this->resolve('byNameString', ['search' => 'hello']);
        $this->assertSame('hello', $search);
    }

    public function test_string_empty(): void
    {
        [$search] = $this->resolve('byNameString', ['search' => '']);
        $this->assertSame('', $search);
    }

    public function test_string_missing_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Required query parameter 'search' is missing");
        $this->resolve('byNameString', []);
    }

    // ── float ─────────────────────────────────────────────────────────────────

    public function test_float(): void
    {
        [$ratio] = $this->resolve('byNameFloat', ['ratio' => '1.5']);
        $this->assertSame(1.5, $ratio);
    }

    public function test_float_invalid_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be a float");
        $this->resolve('byNameFloat', ['ratio' => 'abc']);
    }

    // ── bool ──────────────────────────────────────────────────────────────────

    public function test_bool_true_values(): void
    {
        foreach (['true', '1', 'yes', 'on'] as $val) {
            [$active] = $this->resolve('byNameBool', ['active' => $val]);
            $this->assertTrue($active, "Expected true for '$val'");
        }
    }

    public function test_bool_false_values(): void
    {
        foreach (['false', '0', 'no', 'off'] as $val) {
            [$active] = $this->resolve('byNameBool', ['active' => $val]);
            $this->assertFalse($active, "Expected false for '$val'");
        }
    }

    public function test_bool_invalid_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be a boolean");
        $this->resolve('byNameBool', ['active' => 'null']);
    }

    // ── array ─────────────────────────────────────────────────────────────────

    public function test_array(): void
    {
        [$ids] = $this->resolve('byNameArray', ['ids' => ['1', '2', '3']]);
        $this->assertSame(['1', '2', '3'], $ids);
    }

    public function test_array_scalar_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be an array (use bracket notation: key[]=val)");
        $this->resolve('byNameArray', ['ids' => '1']);
    }

    // ── Custom name ───────────────────────────────────────────────────────────

    public function test_custom_name_resolved(): void
    {
        [$pageSize] = $this->resolve('customName', ['page_size' => '25']);
        $this->assertSame(25, $pageSize);
    }

    public function test_custom_name_wrong_key_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->resolve('customName', ['pageSize' => '25']);
    }

    // ── Nullable ──────────────────────────────────────────────────────────────

    public function test_nullable_present(): void
    {
        [$page] = $this->resolve('nullable', ['page' => '2']);
        $this->assertSame(2, $page);
    }

    public function test_nullable_absent_returns_null(): void
    {
        [$page] = $this->resolve('nullable', []);
        $this->assertNull($page);
    }

    public function test_nullable_empty_string_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be an integer");
        $this->resolve('nullable', ['page' => '']);
    }

    // ── Default value ─────────────────────────────────────────────────────────

    public function test_default_used_when_absent(): void
    {
        [$page] = $this->resolve('withDefault', []);
        $this->assertSame(1, $page);
    }

    public function test_default_overridden_when_present(): void
    {
        [$page] = $this->resolve('withDefault', ['page' => '5']);
        $this->assertSame(5, $page);
    }

    // ── BackedEnum: string ────────────────────────────────────────────────────

    public function test_string_backed_enum(): void
    {
        [$status] = $this->resolve('enumString', ['status' => 'active']);
        $this->assertSame(ReqParamStatus::ACTIVE, $status);
    }

    public function test_string_backed_enum_invalid_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be one of [active, inactive]");
        $this->resolve('enumString', ['status' => 'unknown']);
    }

    // ── BackedEnum: int ───────────────────────────────────────────────────────

    public function test_int_backed_enum(): void
    {
        [$code] = $this->resolve('enumInt', ['code' => '1']);
        $this->assertSame(ReqParamCode::OK, $code);
    }

    public function test_int_backed_enum_non_numeric_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be one of");
        $this->resolve('enumInt', ['code' => 'abc']);
    }

    public function test_int_backed_enum_invalid_value_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be one of [1, 0]");
        $this->resolve('enumInt', ['code' => '99']);
    }

    // ── DateTimeImmutable ─────────────────────────────────────────────────────

    public function test_datetime_parsed(): void
    {
        [$date] = $this->resolve('dateTime', ['date' => '2024-01-31']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame('2024-01-31', $date->format('Y-m-d'));
    }

    public function test_datetime_with_time(): void
    {
        [$date] = $this->resolve('dateTime', ['date' => '2024-01-31T12:30:00']);
        $this->assertSame('2024-01-31 12:30:00', $date->format('Y-m-d H:i:s'));
    }

    public function test_datetime_empty_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("has invalid date ''");
        $this->resolve('dateTime', ['date' => '']);
    }

    public function test_datetime_nullable_absent_returns_null(): void
    {
        [$from] = $this->resolve('nullableDate', []);
        $this->assertNull($from);
    }

    public function test_datetime_nullable_empty_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("has invalid date ''");
        $this->resolve('nullableDate', ['from' => '']);
    }

    // ── DateTime mutable ──────────────────────────────────────────────────────

    public function test_datetime_mutable_parsed(): void
    {
        [$date] = $this->resolve('dateTimeMutable', ['date' => '2024-06-15']);
        $this->assertInstanceOf(\DateTime::class, $date);
        $this->assertSame('2024-06-15', $date->format('Y-m-d'));
    }

    public function test_datetime_mutable_empty_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("has invalid date ''");
        $this->resolve('dateTimeMutable', ['date' => '']);
    }

    // ── Nullable array ────────────────────────────────────────────────────────

    public function test_nullable_array_absent_returns_null(): void
    {
        [$ids] = $this->resolve('nullableArray', []);
        $this->assertNull($ids);
    }

    public function test_nullable_array_present(): void
    {
        [$ids] = $this->resolve('nullableArray', ['ids' => ['5', '6']]);
        $this->assertSame(['5', '6'], $ids);
    }

    public function test_nullable_array_scalar_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be an array");
        $this->resolve('nullableArray', ['ids' => 'foo']);
    }

    // ── BcMath\Number ─────────────────────────────────────────────────────────

    public function test_bcmath_integer(): void
    {
        if (!extension_loaded('bcmath')) { $this->markTestSkipped('ext-bcmath not available'); }
        [$num] = $this->resolve('bcNumber', ['value' => '42']);
        $this->assertInstanceOf(\BcMath\Number::class, $num);
        $this->assertSame('42', (string) $num);
    }

    public function test_bcmath_decimal_precision(): void
    {
        if (!extension_loaded('bcmath')) { $this->markTestSkipped('ext-bcmath not available'); }
        [$num] = $this->resolve('bcNumber', ['value' => '1.1']);
        $this->assertSame('1.1', (string) $num);
    }

    public function test_bcmath_invalid_throws(): void
    {
        if (!extension_loaded('bcmath')) { $this->markTestSkipped('ext-bcmath not available'); }
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be a numeric value");
        $this->resolve('bcNumber', ['value' => 'abc']);
    }

    // ── Decimal\Decimal ───────────────────────────────────────────────────────

    public function test_decimal_integer(): void
    {
        if (!extension_loaded('decimal')) { $this->markTestSkipped('ext-decimal not available'); }
        [$num] = $this->resolve('decimal', ['value' => '42']);
        $this->assertInstanceOf(\Decimal\Decimal::class, $num);
        $this->assertSame('42', (string) $num);
    }

    public function test_decimal_precision(): void
    {
        if (!extension_loaded('decimal')) { $this->markTestSkipped('ext-decimal not available'); }
        [$num] = $this->resolve('decimal', ['value' => '1.1']);
        $this->assertSame('1.1', (string) $num);
    }

    public function test_decimal_invalid_throws(): void
    {
        if (!extension_loaded('decimal')) { $this->markTestSkipped('ext-decimal not available'); }
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be a numeric value");
        $this->resolve('decimal', ['value' => 'abc']);
    }

    // ── camelCase → snake_case / kebab-case lookup ────────────────────────────

    public function test_camel_param_resolved_from_camel_case(): void
    {
        [$val] = $this->resolve('camelParam', ['pageSize' => '25']);
        $this->assertSame(25, $val);
    }

    public function test_camel_param_resolved_from_snake_case(): void
    {
        [$val] = $this->resolve('camelParam', ['page_size' => '30']);
        $this->assertSame(30, $val);
    }

    public function test_camel_param_resolved_from_kebab_case(): void
    {
        [$val] = $this->resolve('camelParam', ['page-size' => '15']);
        $this->assertSame(15, $val);
    }

    // ── #[Constraint] on scalar param — fires without #[Valid] ───────────────

    public function test_constraint_passes_on_valid_value(): void
    {
        [$id] = $this->resolve('constrainedId', ['id' => '5']);
        $this->assertSame(5, $id);
    }

    public function test_constraint_throws_on_violation(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('constrainedId', ['id' => '-1']);
    }

    public function test_constraint_throws_on_zero(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('constrainedId', ['id' => '0']);
    }

    // ── #[Constraint] custom message + {key} i18n resolution ─────────────────

    public function test_custom_message_propagates_through_resolver(): void
    {
        try {
            $this->resolve('constrainedIdWithCustomMessage', ['id' => '-1']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['id' => ['must be > 0']], $e->getErrors());
        }
    }

    public function test_brace_marker_resolved_through_locale(): void
    {
        $tmpDir = $this->setUpLangDir([
            'order' => [
                'id_must_be_positive' => 'Поле «:field» должно быть положительным',
            ],
        ], 'ru');
        try {
            try {
                $this->resolve('constrainedIdWithI18nMessage', ['id' => '-1']);
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                self::assertSame(
                    ['id' => ['Поле «id» должно быть положительным']],
                    $e->getErrors()
                );
            }
        } finally {
            $this->tearDownLangDir($tmpDir, 'ru');
        }
    }

    public function test_brace_marker_substitutes_constraint_props(): void
    {
        $tmpDir = $this->setUpLangDir([
            'order' => [
                'name_too_long' => 'Поле «:field»: длина не более :max символов',
            ],
        ], 'ru');
        try {
            try {
                $this->resolve('constrainedNameWithI18nMessage', ['name' => 'hello']);
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                // :field → 'name', :max → 3 (from Size's public $max property)
                self::assertSame(
                    ['name' => ['Поле «name»: длина не более 3 символов']],
                    $e->getErrors()
                );
            }
        } finally {
            $this->tearDownLangDir($tmpDir, 'ru');
        }
    }

    public function test_brace_marker_with_unknown_key_falls_back_to_key(): void
    {
        $tmpDir = $this->setUpLangDir([], 'en');
        try {
            try {
                $this->resolve('constrainedIdWithI18nMessage', ['id' => '-1']);
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                self::assertSame(
                    ['id' => ['order.id_must_be_positive']],
                    $e->getErrors()
                );
            }
        } finally {
            $this->tearDownLangDir($tmpDir, 'en');
        }
    }

    private function setUpLangDir(array $dictionary, string $lang): string
    {
        $tmpDir = sys_get_temp_dir() . '/winter-kernel-i18n-' . bin2hex(random_bytes(4));
        mkdir($tmpDir);
        file_put_contents(
            $tmpDir . "/$lang.php",
            '<?php return ' . var_export($dictionary, true) . ';'
        );
        Locale::setBasePath($tmpDir);
        Locale::set($lang);
        return $tmpDir;
    }

    private function tearDownLangDir(string $tmpDir, string $lang): void
    {
        @unlink($tmpDir . "/$lang.php");
        @rmdir($tmpDir);
        Locale::setBasePath('');
    }

    // ── Union type ────────────────────────────────────────────────────────────

    public function test_union_type_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Union/intersection type");

        $fixture = new class {
            public function action(#[RequestParam] int|string $value): void {}
        };

        ParameterResolver::resolve(
            new ReflectionMethod($fixture::class, 'action'),
            $this->request,
            $this->response,
            [],
        );
    }
}