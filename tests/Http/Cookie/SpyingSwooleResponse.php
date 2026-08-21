<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Cookie;

/**
 * Lives in its own file on purpose.
 *
 * It extends `Swoole\Http\Response`, so declaring it costs the extension: PHP resolves a
 * parent class the moment the file is parsed. Inside the test file that made the whole
 * class unloadable where Swoole is absent — PHPUnit's suite loader `require_once`s every
 * `*Test.php` before running anything, so CI died with `Class "Swoole\Http\Response" not
 * found` at collection time, long before the per-test `markTestSkipped()` could speak.
 *
 * Here the file matches no test suffix, so nothing loads it up front; the autoloader pulls
 * it in only when a test actually names the class — and those tests skip without Swoole.
 *
 * What it does: records what was asked of it instead of writing to a socket. Built through
 * `newInstanceWithoutConstructor()`, so no connection is involved.
 */
final class SpyingSwooleResponse extends \Swoole\Http\Response
{
    /** @var array<string, array<int, string>|string> */
    public array $written = [];

    /** @var list<array<int, mixed>> Calls to the native cookie API, which must stay empty. */
    public array $cookieCalls = [];

    public function header(string $key, array|string $value, bool $format = true): bool
    {
        $this->written[$key] = $value;
        return true;
    }

    public function cookie(
        \Swoole\Http\Cookie|string $name_or_object,
        string $value = '',
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httponly = false,
        string $samesite = '',
        string $priority = '',
        bool $partitioned = false,
    ): bool {
        $this->cookieCalls[] = func_get_args();
        return true;
    }
}
