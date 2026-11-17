<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Front;

use Flytachi\Winter\Kernel\Dev\FrontProxy;
use PHPUnit\Framework\TestCase;

/** How long a frontend's program has to answer: the server's request timeout, or none. */
final class FrontProxyTimeoutTest extends TestCase
{
    private function timeout(): float
    {
        return (new \ReflectionProperty(FrontProxy::class, 'timeout'))->getValue();
    }

    protected function tearDown(): void
    {
        FrontProxy::timeout(30.0);
    }

    public function test_the_request_timeout_is_the_programs(): void
    {
        FrontProxy::timeout(12.5);

        self::assertSame(12.5, $this->timeout());
    }

    public function test_no_request_timeout_is_no_limit(): void
    {
        FrontProxy::timeout(0.0);

        self::assertSame(-1.0, $this->timeout(), 'an application with no deadline cuts no answer either');
    }
}
