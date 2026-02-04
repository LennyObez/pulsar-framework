<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Resilience\CircuitBreaker;
use Pulsar\Resilience\CircuitBreakerRegistry;
use Pulsar\Resilience\CircuitBreakerState;

#[CoversClass(CircuitBreakerRegistry::class)]
final class CircuitBreakerRegistryTest extends TestCase
{
    #[Test]
    public function getCreatesNewBreaker(): void
    {
        $registry = new CircuitBreakerRegistry(new CircuitBreakerConfig());

        $breaker = $registry->get('api');

        self::assertInstanceOf(CircuitBreaker::class, $breaker);
        self::assertSame('api', $breaker->name());
    }

    #[Test]
    public function getReturnsSameBreakerForSameName(): void
    {
        $registry = new CircuitBreakerRegistry(new CircuitBreakerConfig());

        $first = $registry->get('api');
        $second = $registry->get('api');

        self::assertSame($first, $second);
    }

    #[Test]
    public function hasReturnsTrueForCreatedAndFalseForUncreated(): void
    {
        $registry = new CircuitBreakerRegistry(new CircuitBreakerConfig());

        self::assertFalse($registry->has('api'));

        $registry->get('api');

        self::assertTrue($registry->has('api'));
        self::assertFalse($registry->has('unknown'));
    }

    #[Test]
    public function allReturnsAllCreatedBreakers(): void
    {
        $registry = new CircuitBreakerRegistry(new CircuitBreakerConfig());

        self::assertSame([], $registry->all());

        $registry->get('api');
        $registry->get('database');

        $all = $registry->all();

        self::assertCount(2, $all);
        self::assertArrayHasKey('api', $all);
        self::assertArrayHasKey('database', $all);
    }

    #[Test]
    public function resetResetsSpecificBreaker(): void
    {
        $registry = new CircuitBreakerRegistry(new CircuitBreakerConfig(
            failureThreshold: 1,
            openTimeoutSeconds: 60,
        ));

        $breaker = $registry->get('api');
        $breaker->recordFailure();
        self::assertSame(CircuitBreakerState::Open, $breaker->state());

        $registry->reset('api');

        self::assertSame(CircuitBreakerState::Closed, $breaker->state());
    }

    #[Test]
    public function resetAllResetsAllBreakers(): void
    {
        $registry = new CircuitBreakerRegistry(new CircuitBreakerConfig(
            failureThreshold: 1,
            openTimeoutSeconds: 60,
        ));

        $api = $registry->get('api');
        $db = $registry->get('database');

        $api->recordFailure();
        $db->recordFailure();

        self::assertSame(CircuitBreakerState::Open, $api->state());
        self::assertSame(CircuitBreakerState::Open, $db->state());

        $registry->resetAll();

        self::assertSame(CircuitBreakerState::Closed, $api->state());
        self::assertSame(CircuitBreakerState::Closed, $db->state());
    }
}
