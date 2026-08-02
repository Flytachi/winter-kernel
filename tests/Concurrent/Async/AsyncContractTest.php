<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Concurrent\Async;

use Flytachi\Winter\Kernel\Concurrent\Async\Async;
use Flytachi\Winter\Kernel\Concurrent\Async\AsyncException;
use Flytachi\Winter\Kernel\Concurrent\Async\Proxy\ProxyFactory;
use Flytachi\Winter\Kernel\Concurrent\CompletableFuture;
use Flytachi\Winter\Kernel\Concurrent\Future;
use Flytachi\Winter\Kernel\Core\KernelConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The `#[Async]` contract, enforced where it is meant to be — at proxy generation.
 *
 * A violation that slipped through here would not fail loudly: the proxy would simply
 * not override the method, and the call would run synchronously while the developer
 * believed otherwise. So each rule gets a class that breaks exactly one of them.
 */
final class AsyncContractTest extends TestCase
{
    private string $volatile = '';
    private ?string $originalVolatile = null;

    protected function setUp(): void
    {
        $prop = new ReflectionProperty(KernelConfig::class, 'pathStorageVolatile');
        $this->originalVolatile = $prop->isInitialized() ? $prop->getValue() : null;

        $this->volatile = sys_get_temp_dir() . '/wk_async_' . getmypid() . '_' . bin2hex(random_bytes(4));
        KernelConfig::$pathStorageVolatile = $this->volatile;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->volatile . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->volatile . '/*') ?: [] as $dir) {
            is_dir($dir) ? @rmdir($dir) : @unlink($dir);
        }
        @rmdir($this->volatile);

        if ($this->originalVolatile !== null) {
            KernelConfig::$pathStorageVolatile = $this->originalVolatile;
        }
    }

    private function proxyFor(string $class): string
    {
        return ProxyFactory::forKernel(true)->proxyFor(new ReflectionClass($class));
    }

    // ── Accepted ───────────────────────────────────────────────────────────────

    public function test_a_valid_class_is_proxied(): void
    {
        $proxy = $this->proxyFor(AsyncFixture::class);

        self::assertTrue(is_subclass_of($proxy, AsyncFixture::class), 'the proxy extends the original');
        self::assertSame(AsyncFixture::class, $proxy::proxyTarget());
    }

    public function test_only_annotated_methods_are_overridden(): void
    {
        $proxy = new ReflectionClass($this->proxyFor(AsyncFixture::class));

        self::assertTrue($proxy->hasMethod('fire'));
        self::assertSame($proxy->getName(), $proxy->getMethod('fire')->getDeclaringClass()->getName());
        self::assertNotSame(
            $proxy->getName(),
            $proxy->getMethod('plain')->getDeclaringClass()->getName(),
            'a method without #[Async] must stay the original',
        );
    }

    public function test_a_protected_method_is_allowed(): void
    {
        // Self-calls still go through the proxy, so protected is legitimate — the
        // contract is "not private", not "public only".
        $proxy = new ReflectionClass($this->proxyFor(ProtectedAsyncFixture::class));

        self::assertSame($proxy->getName(), $proxy->getMethod('step')->getDeclaringClass()->getName());
    }

    // ── Rejected ───────────────────────────────────────────────────────────────

    /** @return list<array{0: class-string, 1: string}> */
    public static function violations(): array
    {
        return [
            'final class'      => [FinalClassFixture::class, 'final'],
            'static method'    => [StaticMethodFixture::class, 'static'],
            'final method'     => [FinalMethodFixture::class, 'final'],
            'private method'   => [PrivateMethodFixture::class, 'private'],
            'bad return type'  => [BadReturnFixture::class, 'return type'],
            'no return type'   => [NoReturnTypeFixture::class, 'return type'],
            'by-reference arg' => [ByRefFixture::class, 'by reference'],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('violations')]
    public function test_a_violation_is_reported_at_generation(string $class, string $needle): void
    {
        $this->expectException(AsyncException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/i');

        $this->proxyFor($class);
    }
}

// ── Fixtures ────────────────────────────────────────────────────────────────────

class AsyncFixture
{
    #[Async]
    public function fire(string $to): void
    {
    }

    #[Async]
    public function compute(int $a, int $b): Future
    {
        return CompletableFuture::completedFuture($a + $b);
    }

    public function plain(int $x): int
    {
        return $x * 2;
    }
}

class ProtectedAsyncFixture
{
    #[Async]
    protected function step(): void
    {
    }
}

final class FinalClassFixture
{
    #[Async]
    public function go(): void
    {
    }
}

class StaticMethodFixture
{
    #[Async]
    public static function go(): void
    {
    }
}

class FinalMethodFixture
{
    #[Async]
    final public function go(): void
    {
    }
}

class PrivateMethodFixture
{
    public function trigger(): void
    {
        $this->go();
    }

    #[Async]
    private function go(): void
    {
    }
}

class BadReturnFixture
{
    #[Async]
    public function go(): int
    {
        return 1;
    }
}

class NoReturnTypeFixture
{
    #[Async]
    public function go()
    {
    }
}

class ByRefFixture
{
    #[Async]
    public function go(array &$rows): void
    {
    }
}
