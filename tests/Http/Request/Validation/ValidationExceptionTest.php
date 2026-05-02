<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class ValidationExceptionTest extends TestCase
{
    public function testGetErrorsReturnsPassedMap(): void
    {
        $errors = [
            'title'  => ['is required'],
            'amount' => ['must be at least 1', 'must not exceed 1000'],
        ];
        $ex = new ValidationException($errors);
        self::assertSame($errors, $ex->getErrors());
    }

    public function testMessageIsValidationFailed(): void
    {
        $ex = new ValidationException([]);
        self::assertSame('Validation failed', $ex->getMessage());
    }

    public function testEmptyErrorsAllowed(): void
    {
        $ex = new ValidationException([]);
        self::assertSame([], $ex->getErrors());
    }

    public function testNestedPathErrors(): void
    {
        $errors = [
            'filter.minPrice' => ['must be at least 0'],
            'filter.maxPrice' => ['must not exceed 1000000'],
        ];
        $ex = new ValidationException($errors);
        self::assertSame($errors, $ex->getErrors());
    }

    public function testIsInstanceOfResponseException(): void
    {
        $ex = new ValidationException([]);
        self::assertInstanceOf(\Flytachi\Winter\K2\Http\Response\ResponseException::class, $ex);
    }
}
