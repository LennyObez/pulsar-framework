<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\CircuitBreakerState;

#[CoversClass(CircuitBreakerState::class)]
final class CircuitBreakerStateTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('closed', CircuitBreakerState::Closed->value);
        self::assertSame('open', CircuitBreakerState::Open->value);
        self::assertSame('half_open', CircuitBreakerState::HalfOpen->value);
    }

    #[Test]
    public function fromValidString(): void
    {
        self::assertSame(CircuitBreakerState::Closed, CircuitBreakerState::from('closed'));
        self::assertSame(CircuitBreakerState::HalfOpen, CircuitBreakerState::from('half_open'));
    }
}
