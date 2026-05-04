<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\ParameterResolver;
use Flytachi\Winter\K2\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\K2\Http\Request\RequestException;
use Flytachi\Winter\K2\Http\Request\Validation\Positive;
use Flytachi\Winter\K2\Http\Request\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Fixture enums ─────────────────────────────────────────────────────────────

enum PathVarStatus: string
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';
}

enum PathVarCode: int
{
    case OK   = 1;
    case FAIL = 0;
}

// ── Fixture controller ────────────────────────────────────────────────────────

class PathVariableFixture
{
    public function byNameInt(#[PathVariable] int $id): void {}
    public function byNameString(#[PathVariable] string $slug): void {}
    public function byNameFloat(#[PathVariable] float $ratio): void {}
    public function byNameBool(#[PathVariable] bool $active): void {}
    public function customName(#[PathVariable('userId')] int $id): void {}
    public function nullable(#[PathVariable] ?int $id): void {}
    public function withDefault(#[PathVariable] int $id = 99): void {}
    public function multiple(#[PathVariable] int $userId, #[PathVariable] int $postId): void {}
    public function enumString(#[PathVariable] PathVarStatus $status): void {}
    public function enumInt(#[PathVariable] PathVarCode $code): void {}
    public function dateTime(#[PathVariable] \DateTimeImmutable $date): void {}
    public function dateTimeMutable(#[PathVariable] \DateTime $date): void {}
    public function nullableDate(#[PathVariable] ?\DateTimeImmutable $date): void {}
    public function autoMatch(int $id): void {}
    public function autoMatchRequired(int $id): void {}
    public function bcNumber(#[PathVariable] \BcMath\Number $value): void {}
    public function decimal(#[PathVariable] \Decimal\Decimal $value): void {}
    public function positiveId(#[PathVariable, Positive] int $id): void {}
}

// ─────────────────────────────────────────────────────────────────────────────

class PathVariableTest extends TestCase
{
    private HttpRequest  $request;
    private HttpResponse $response;

    protected function setUp(): void
    {
        $this->request  = $this->createStub(HttpRequest::class);
        $this->response = $this->createStub(HttpResponse::class);
    }

    private function resolve(string $method, array $pathParams): array
    {
        return ParameterResolver::resolve(
            new ReflectionMethod(PathVariableFixture::class, $method),
            $this->request,
            $this->response,
            $pathParams,
        );
    }

    // ── int ───────────────────────────────────────────────────────────────────

    public function test_int(): void
    {
        [$id] = $this->resolve('byNameInt', ['id' => '42']);
        $this->assertSame(42, $id);
    }

    public function test_int_invalid_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Path variable 'id' must be an integer, got 'abc'");
        $this->resolve('byNameInt', ['id' => 'abc']);
    }

    public function test_int_missing_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Path variable 'id' is missing");
        $this->resolve('byNameInt', []);
    }

    // ── string ────────────────────────────────────────────────────────────────

    public function test_string(): void
    {
        [$slug] = $this->resolve('byNameString', ['slug' => 'hello-world']);
        $this->assertSame('hello-world', $slug);
    }

    public function test_string_missing_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Path variable 'slug' is missing");
        $this->resolve('byNameString', []);
    }

    // ── float ─────────────────────────────────────────────────────────────────

    public function test_float(): void
    {
        [$ratio] = $this->resolve('byNameFloat', ['ratio' => '3.14']);
        $this->assertSame(3.14, $ratio);
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
        $this->resolve('byNameBool', ['active' => 'maybe']);
    }

    // ── Custom name ───────────────────────────────────────────────────────────

    public function test_custom_name_resolved(): void
    {
        [$id] = $this->resolve('customName', ['userId' => '7']);
        $this->assertSame(7, $id);
    }

    public function test_custom_name_wrong_key_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->resolve('customName', ['id' => '7']);
    }

    // ── Auto-match by parameter name ──────────────────────────────────────────

    public function test_auto_match_without_annotation(): void
    {
        [$id] = $this->resolve('autoMatch', ['id' => '5']);
        $this->assertSame(5, $id);
    }

    // ── Multiple ──────────────────────────────────────────────────────────────

    public function test_multiple_path_variables(): void
    {
        [$userId, $postId] = $this->resolve('multiple', ['userId' => '3', 'postId' => '12']);
        $this->assertSame(3, $userId);
        $this->assertSame(12, $postId);
    }

    // ── Nullable ──────────────────────────────────────────────────────────────

    public function test_nullable_present(): void
    {
        [$id] = $this->resolve('nullable', ['id' => '10']);
        $this->assertSame(10, $id);
    }

    public function test_nullable_absent_returns_null(): void
    {
        [$id] = $this->resolve('nullable', []);
        $this->assertNull($id);
    }

    // ── Default value ─────────────────────────────────────────────────────────

    public function test_default_used_when_absent(): void
    {
        [$id] = $this->resolve('withDefault', []);
        $this->assertSame(99, $id);
    }

    public function test_default_overridden_when_present(): void
    {
        [$id] = $this->resolve('withDefault', ['id' => '5']);
        $this->assertSame(5, $id);
    }

    // ── BackedEnum: string ────────────────────────────────────────────────────

    public function test_string_backed_enum(): void
    {
        [$status] = $this->resolve('enumString', ['status' => 'active']);
        $this->assertSame(PathVarStatus::ACTIVE, $status);
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
        $this->assertSame(PathVarCode::OK, $code);
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

    public function test_datetime_immutable_parsed(): void
    {
        [$date] = $this->resolve('dateTime', ['date' => '2024-01-31']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame('2024-01-31', $date->format('Y-m-d'));
    }

    public function test_datetime_immutable_with_time(): void
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

    // ── Nullable DateTimeImmutable ────────────────────────────────────────────

    public function test_nullable_date_absent_returns_null(): void
    {
        [$date] = $this->resolve('nullableDate', []);
        $this->assertNull($date);
    }

    public function test_nullable_date_present(): void
    {
        [$date] = $this->resolve('nullableDate', ['date' => '2024-03-01']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame('2024-03-01', $date->format('Y-m-d'));
    }

    // ── Auto-match absent without default ────────────────────────────────────

    public function test_auto_match_absent_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolve('autoMatchRequired', []);
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

    // ── #[Constraint] on path variable — fires without #[Valid] ──────────────

    public function test_constraint_passes_on_valid_path(): void
    {
        [$id] = $this->resolve('positiveId', ['id' => '7']);
        $this->assertSame(7, $id);
    }

    public function test_constraint_fails_on_negative_path(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('positiveId', ['id' => '-1']);
    }

    public function test_constraint_fails_on_zero_path(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('positiveId', ['id' => '0']);
    }

    // ── Union type ────────────────────────────────────────────────────────────

    public function test_union_type_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Union/intersection type");

        $fixture = new class {
            public function action(#[PathVariable] int|string $id): void {}
        };

        ParameterResolver::resolve(
            new ReflectionMethod($fixture::class, 'action'),
            $this->request,
            $this->response,
            ['id' => '1'],
        );
    }
}