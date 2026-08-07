<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Collector;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\DI\Attribute\Request;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\Kernel\Collector\ScopeConflictException;
use Flytachi\Winter\Kernel\Collector\ScopeGraphCollector;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

// ── Fixtures ──────────────────────────────────────────────────────────────────

#[Request]
class SgAuthContext
{
    public string $user = '';
}

class SgPlainService
{
    #[Autowired]
    public ?SgAuthContext $context = null;
}

#[Singleton]
class SgDirectHolder
{
    #[Autowired]
    public ?SgAuthContext $context = null;
}

#[Singleton]
class SgIndirectHolder
{
    #[Autowired]
    public ?SgPlainService $service = null;
}

#[Singleton]
class SgNamedHolder
{
    #[Inject(SgAuthContext::class)]
    public ?object $context = null;
}

#[Singleton]
class SgHarmless
{
    #[Autowired]
    public ?SgPlainRepository $repository = null;

    public string $plain = 'not injected';
}

class SgPlainRepository
{
}

#[Singleton]
class SgCycleA
{
    #[Autowired]
    public ?SgCycleB $b = null;
}

#[Singleton]
class SgCycleB
{
    #[Autowired]
    public ?SgCycleA $a = null;
}

/**
 * A singleton holding a request-scoped bean must stop the boot.
 *
 * The combination cannot be made to work by trying harder: a singleton resolves its
 * properties once, so the request-scoped object it captured belongs to whichever request
 * came first, and every later request keeps seeing that one. It never throws at runtime —
 * it serves the wrong data. Refusing to start is the only honest outcome.
 */
final class ScopeGraphCollectorTest extends TestCase
{
    private function graphOf(string ...$classes): ScopeGraphCollector
    {
        $collector = new ScopeGraphCollector();
        foreach ($classes as $class) {
            $collector->collect($class, new ReflectionClass($class));
        }

        return $collector;
    }

    public function test_a_singleton_holding_a_request_bean_is_refused(): void
    {
        $graph = $this->graphOf(SgAuthContext::class, SgDirectHolder::class);

        $this->expectException(ScopeConflictException::class);
        $graph->assertNoFrozenRequestScope();
    }

    /**
     * The transitive case is the one that surprises people: every link looks correct on
     * its own, and only the chain is wrong.
     */
    public function test_it_follows_the_chain_through_an_innocent_middleman(): void
    {
        $graph = $this->graphOf(SgAuthContext::class, SgPlainService::class, SgIndirectHolder::class);

        $this->expectException(ScopeConflictException::class);
        $graph->assertNoFrozenRequestScope();
    }

    /**
     * The message has to name the middle of the chain, since that is where the mistake
     * hides — pointing only at the endpoints leaves the reader to find the link.
     */
    public function test_the_message_names_the_whole_path(): void
    {
        $graph = $this->graphOf(SgAuthContext::class, SgPlainService::class, SgIndirectHolder::class);

        try {
            $graph->assertNoFrozenRequestScope();
            self::fail('Expected the boot to be refused.');
        } catch (ScopeConflictException $e) {
            self::assertStringContainsString(SgIndirectHolder::class, $e->getMessage());
            self::assertStringContainsString(SgPlainService::class, $e->getMessage(), 'The middle link.');
            self::assertStringContainsString(SgAuthContext::class, $e->getMessage());
            self::assertStringContainsString('service', $e->getMessage(), 'The property name.');
        }
    }

    public function test_an_explicit_inject_target_counts_too(): void
    {
        $graph = $this->graphOf(SgAuthContext::class, SgNamedHolder::class);

        $this->expectException(ScopeConflictException::class);
        $graph->assertNoFrozenRequestScope();
    }

    public function test_a_singleton_with_only_shareable_dependencies_passes(): void
    {
        $graph = $this->graphOf(SgPlainRepository::class, SgHarmless::class);

        $graph->assertNoFrozenRequestScope();

        $this->addToAssertionCount(1);
    }

    /**
     * A request-scoped bean held by something that does not outlive the request is the
     * correct pattern, and must not be flagged.
     */
    public function test_a_transient_holder_is_fine(): void
    {
        $graph = $this->graphOf(SgAuthContext::class, SgPlainService::class);

        $graph->assertNoFrozenRequestScope();

        $this->addToAssertionCount(1);
    }

    /**
     * A dependency cycle must not hang the walk — it is a separate problem the container
     * reports on its own, and this check has no business turning it into an infinite loop.
     */
    public function test_a_dependency_cycle_terminates(): void
    {
        $graph = $this->graphOf(SgCycleA::class, SgCycleB::class);

        $graph->assertNoFrozenRequestScope();

        $this->addToAssertionCount(1);
    }
}
