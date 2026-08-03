<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\Counter;
use Pulsar\Observability\Metrics\Exception\MetricsException;
use Pulsar\Observability\Metrics\Gauge;
use Pulsar\Observability\Metrics\Histogram;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Metrics\MetricType;

#[CoversClass(MetricRegistry::class)]
final class MetricRegistryTest extends TestCase
{
    #[Test]
    public function counterCreateOrReturn(): void
    {
        $registry = new MetricRegistry();

        $c1 = $registry->counter('requests_total', 'Total requests');
        $c2 = $registry->counter('requests_total');

        self::assertSame($c1, $c2);
        self::assertInstanceOf(Counter::class, $c1);
    }

    #[Test]
    public function gaugeCreateOrReturn(): void
    {
        $registry = new MetricRegistry();

        $g1 = $registry->gauge('temperature');
        $g2 = $registry->gauge('temperature');

        self::assertSame($g1, $g2);
        self::assertInstanceOf(Gauge::class, $g1);
    }

    #[Test]
    public function histogramCreateOrReturn(): void
    {
        $registry = new MetricRegistry();

        $h1 = $registry->histogram('duration');
        $h2 = $registry->histogram('duration');

        self::assertSame($h1, $h2);
        self::assertInstanceOf(Histogram::class, $h1);
    }

    #[Test]
    public function throwsOnTypeMismatch(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('metric');

        $this->expectException(MetricsException::class);
        $this->expectExceptionMessageIsOrContains('already registered as counter');

        $registry->gauge('metric');
    }

    #[Test]
    public function hasAndTypeOf(): void
    {
        $registry = new MetricRegistry();
        $registry->counter('c');
        $registry->gauge('g');

        self::assertTrue($registry->has('c'));
        self::assertTrue($registry->has('g'));
        self::assertFalse($registry->has('unknown'));
        self::assertSame(MetricType::Counter, $registry->typeOf('c'));
        self::assertSame(MetricType::Gauge, $registry->typeOf('g'));
        self::assertNull($registry->typeOf('unknown'));
    }
}
