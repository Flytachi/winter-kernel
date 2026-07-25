<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Concurrent;

use Flytachi\Winter\K2\Concurrent\RejectPolicy;
use PHPUnit\Framework\TestCase;

final class RejectPolicyTest extends TestCase
{
    public function test_cases(): void
    {
        self::assertCount(3, RejectPolicy::cases());
        self::assertSame(
            ['ABORT', 'CALLER_RUNS', 'DISCARD'],
            array_map(static fn(RejectPolicy $p): string => $p->name, RejectPolicy::cases()),
        );
    }
}
