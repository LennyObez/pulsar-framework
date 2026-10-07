<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\FiberScopedContextProvider;
use Pulsar\Observability\Context\CorrelationContext;

final class FiberScopedContextProviderTest extends TestCase
{
    #[Test]
    public function currentReturnsNullWhenNoScope(): void
    {
        $provider = new FiberScopedContextProvider();

        self::assertNull($provider->current());
    }

    #[Test]
    public function enterPushesContextAndCurrentReturnsIt(): void
    {
        $provider = new FiberScopedContextProvider();
        $ctx = new CorrelationContext(requestId: 'req-1');

        $scope = $provider->enter($ctx);

        self::assertSame('req-1', $provider->current()?->requestId);

        $scope->close();
    }

    #[Test]
    public function closePopsContextFromStack(): void
    {
        $provider = new FiberScopedContextProvider();
        $ctx = new CorrelationContext(requestId: 'req-1');

        $scope = $provider->enter($ctx);
        $scope->close();

        self::assertNull($provider->current());
    }

    #[Test]
    public function nestedScopesWorkCorrectly(): void
    {
        $provider = new FiberScopedContextProvider();
        $outer = new CorrelationContext(requestId: 'outer');
        $inner = new CorrelationContext(requestId: 'inner');

        $outerScope = $provider->enter($outer);
        $current = $provider->current();
        self::assertNotNull($current);
        self::assertSame('outer', $current->requestId);

        $innerScope = $provider->enter($inner);
        $current = $provider->current();
        self::assertNotNull($current);
        self::assertSame('inner', $current->requestId);

        $innerScope->close();
        $current = $provider->current();
        self::assertNotNull($current);
        self::assertSame('outer', $current->requestId);

        $outerScope->close();
        self::assertNull($provider->current());
    }
}
