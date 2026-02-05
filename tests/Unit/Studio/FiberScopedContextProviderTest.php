<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\ContextScope;
use Pulsar\Studio\CorrelationContext;
use Pulsar\Studio\CorrelationContextProviderInterface;
use Pulsar\Studio\FiberScopedContextProvider;

#[CoversClass(FiberScopedContextProvider::class)]
final class FiberScopedContextProviderTest extends TestCase
{
    #[Test]
    public function implementsCorrelationContextProviderInterface(): void
    {
        $provider = new FiberScopedContextProvider();

        self::assertInstanceOf(CorrelationContextProviderInterface::class, $provider);
    }

    #[Test]
    public function currentReturnsNullWhenNoScopeActive(): void
    {
        $provider = new FiberScopedContextProvider();

        self::assertNull($provider->current());
    }

    #[Test]
    public function enterReturnsContextScope(): void
    {
        $provider = new FiberScopedContextProvider();
        $context = new CorrelationContext(requestId: 'req-123');

        $scope = $provider->enter($context);

        self::assertInstanceOf(ContextScope::class, $scope);

        $scope->close();
    }

    #[Test]
    public function currentReturnsActiveContext(): void
    {
        $provider = new FiberScopedContextProvider();
        $context = new CorrelationContext(requestId: 'req-123', traceId: 'trace-456');

        $scope = $provider->enter($context);

        $current = $provider->current();

        self::assertNotNull($current);
        self::assertSame('req-123', $current->requestId);
        self::assertSame('trace-456', $current->traceId);

        $scope->close();
    }

    #[Test]
    public function currentReturnsNullAfterScopeClose(): void
    {
        $provider = new FiberScopedContextProvider();
        $context = new CorrelationContext(requestId: 'req-123');

        $scope = $provider->enter($context);
        $scope->close();

        self::assertNull($provider->current());
    }

    #[Test]
    public function nestedScopesReturnInnermostContext(): void
    {
        $provider = new FiberScopedContextProvider();

        $outerContext = new CorrelationContext(requestId: 'outer');
        $innerContext = new CorrelationContext(requestId: 'inner');

        $outerScope = $provider->enter($outerContext);
        $innerScope = $provider->enter($innerContext);

        $innerCurrent = $provider->current();
        self::assertNotNull($innerCurrent);
        self::assertSame('inner', $innerCurrent->requestId);

        $innerScope->close();
        $outerCurrent = $provider->current();
        self::assertNotNull($outerCurrent);
        self::assertSame('outer', $outerCurrent->requestId);

        $outerScope->close();
        self::assertNull($provider->current());
    }

    #[Test]
    public function multipleLevelsOfNesting(): void
    {
        $provider = new FiberScopedContextProvider();

        $contexts = [];
        $scopes = [];

        for ($i = 1; $i <= 5; $i++) {
            $contexts[$i] = new CorrelationContext(requestId: "req-{$i}");
            $scopes[$i] = $provider->enter($contexts[$i]);

            $current = $provider->current();
            self::assertNotNull($current);
            self::assertSame("req-{$i}", $current->requestId);
        }

        for ($i = 5; $i >= 1; $i--) {
            $current = $provider->current();
            self::assertNotNull($current);
            self::assertSame("req-{$i}", $current->requestId);
            $scopes[$i]->close();
        }

        self::assertNull($provider->current());
    }

    #[Test]
    public function fiberIsolation(): void
    {
        $provider = new FiberScopedContextProvider();

        // Set up a context in the root (non-Fiber) execution
        $rootContext = new CorrelationContext(requestId: 'root');
        $rootScope = $provider->enter($rootContext);

        $fiberSawRoot = null;
        $fiberContextValue = null;

        $fiber = new Fiber(function () use ($provider, &$fiberSawRoot, &$fiberContextValue): void {
            // Inside the Fiber, we should NOT see the root context
            $fiberSawRoot = $provider->current();

            // Set up a Fiber-specific context
            $fiberContext = new CorrelationContext(requestId: 'fiber');
            $fiberScope = $provider->enter($fiberContext);

            $fiberContextValue = $provider->current()?->requestId;

            $fiberScope->close();

            Fiber::suspend();
        });

        $fiber->start();

        // Root should still see its own context
        self::assertSame('root', $provider->current()?->requestId);

        // Fiber should NOT have seen root context (WeakMap isolation)
        self::assertNull($fiberSawRoot);

        // Fiber had its own context
        self::assertSame('fiber', $fiberContextValue);

        $rootScope->close();
    }

    #[Test]
    public function multipleFibersHaveIndependentContexts(): void
    {
        $provider = new FiberScopedContextProvider();

        $fiber1Context = null;
        $fiber2Context = null;

        $fiber1 = new Fiber(function () use ($provider, &$fiber1Context): void {
            $context = new CorrelationContext(requestId: 'fiber-1');
            $scope = $provider->enter($context);
            $fiber1Context = $provider->current()?->requestId;
            Fiber::suspend();
            $scope->close();
        });

        $fiber2 = new Fiber(function () use ($provider, &$fiber2Context): void {
            $context = new CorrelationContext(requestId: 'fiber-2');
            $scope = $provider->enter($context);
            $fiber2Context = $provider->current()?->requestId;
            Fiber::suspend();
            $scope->close();
        });

        $fiber1->start();
        $fiber2->start();

        self::assertSame('fiber-1', $fiber1Context);
        self::assertSame('fiber-2', $fiber2Context);

        // Resume and close
        $fiber1->resume();
        $fiber2->resume();
    }

    #[Test]
    public function contextPreservedAcrossFiberSuspendResume(): void
    {
        $provider = new FiberScopedContextProvider();

        $beforeSuspend = null;
        $afterResume = null;

        $fiber = new Fiber(function () use ($provider, &$beforeSuspend, &$afterResume): void {
            $context = new CorrelationContext(requestId: 'persistent');
            $scope = $provider->enter($context);

            $beforeSuspend = $provider->current()?->requestId;

            Fiber::suspend();

            $afterResume = $provider->current()?->requestId;

            $scope->close();
        });

        $fiber->start();
        $fiber->resume();

        self::assertSame('persistent', $beforeSuspend);
        self::assertSame('persistent', $afterResume);
    }

    #[Test]
    public function scopeCloseIsIdempotent(): void
    {
        $provider = new FiberScopedContextProvider();
        $context = new CorrelationContext(requestId: 'req-123');

        $scope = $provider->enter($context);

        // Close multiple times - should not cause errors
        $scope->close();
        $scope->close();
        $scope->close();

        self::assertNull($provider->current());
    }

    #[Test]
    public function closeOnEmptyStackDoesNotThrow(): void
    {
        $provider = new FiberScopedContextProvider();

        // Manually create a scope and close it when stack is already empty
        $context = new CorrelationContext(requestId: 'req-123');
        $scope1 = $provider->enter($context);
        $scope1->close();

        // Closing again should not throw (idempotent)
        $scope1->close();

        self::assertNull($provider->current());
    }

    #[Test]
    public function rootContextSurvivesFiberExecution(): void
    {
        $provider = new FiberScopedContextProvider();

        $rootContext = new CorrelationContext(requestId: 'root-survives');
        $rootScope = $provider->enter($rootContext);

        $fiber = new Fiber(function () use ($provider): void {
            // Do something in fiber
            $context = new CorrelationContext(requestId: 'fiber-temp');
            $scope = $provider->enter($context);
            $scope->close();
        });

        $fiber->start();

        // Root context should still be intact
        self::assertSame('root-survives', $provider->current()?->requestId);

        $rootScope->close();
    }

    #[Test]
    public function enterWithDifferentContexts(): void
    {
        $provider = new FiberScopedContextProvider();

        $context1 = new CorrelationContext(
            requestId: 'req-1',
            traceId: 'trace-1',
            spanId: 'span-1',
            jobId: 'job-1',
        );

        $context2 = new CorrelationContext(
            requestId: 'req-2',
            traceId: 'trace-2',
            spanId: 'span-2',
            jobId: 'job-2',
        );

        $scope1 = $provider->enter($context1);

        $current1 = $provider->current();
        self::assertNotNull($current1);
        self::assertSame('req-1', $current1->requestId);
        self::assertSame('trace-1', $current1->traceId);
        self::assertSame('span-1', $current1->spanId);
        self::assertSame('job-1', $current1->jobId);

        $scope2 = $provider->enter($context2);

        $current2 = $provider->current();
        self::assertNotNull($current2);
        self::assertSame('req-2', $current2->requestId);
        self::assertSame('trace-2', $current2->traceId);
        self::assertSame('span-2', $current2->spanId);
        self::assertSame('job-2', $current2->jobId);

        $scope2->close();
        $scope1->close();
    }

    #[Test]
    public function freshProviderHasNullCurrent(): void
    {
        $provider = new FiberScopedContextProvider();

        self::assertNull($provider->current());
    }

    #[Test]
    public function multipleProvidersAreIndependent(): void
    {
        $provider1 = new FiberScopedContextProvider();
        $provider2 = new FiberScopedContextProvider();

        $context1 = new CorrelationContext(requestId: 'provider1');
        $context2 = new CorrelationContext(requestId: 'provider2');

        $scope1 = $provider1->enter($context1);
        $scope2 = $provider2->enter($context2);

        $p1Current = $provider1->current();
        $p2Current = $provider2->current();
        self::assertNotNull($p1Current);
        self::assertNotNull($p2Current);
        self::assertSame('provider1', $p1Current->requestId);
        self::assertSame('provider2', $p2Current->requestId);

        $scope1->close();

        self::assertNull($provider1->current());
        $p2AfterClose = $provider2->current();
        self::assertNotNull($p2AfterClose);
        self::assertSame('provider2', $p2AfterClose->requestId);

        $scope2->close();
    }
}
