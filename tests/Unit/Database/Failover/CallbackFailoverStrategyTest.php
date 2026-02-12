<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Failover;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Failover\CallbackFailoverStrategy;

#[CoversClass(CallbackFailoverStrategy::class)]
final class CallbackFailoverStrategyTest extends TestCase
{
    #[Test]
    public function resolveTargetInvokesCallback(): void
    {
        $strategy = new CallbackFailoverStrategy(
            static fn(): string => '10.0.0.99',
        );

        self::assertSame('10.0.0.99', $strategy->resolveTarget());
    }

    #[Test]
    public function nameReturnsCallback(): void
    {
        $strategy = new CallbackFailoverStrategy(
            static fn(): ?string => null,
        );

        self::assertSame('callback', $strategy->name());
    }

    #[Test]
    public function callbackReturningNullYieldsNull(): void
    {
        $strategy = new CallbackFailoverStrategy(
            static fn(): ?string => null,
        );

        self::assertNull($strategy->resolveTarget());
    }
}
