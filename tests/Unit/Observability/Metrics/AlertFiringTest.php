<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\AlertFiring;
use Pulsar\Observability\Metrics\AlertThreshold;

#[CoversClass(AlertFiring::class)]
final class AlertFiringTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $threshold = new AlertThreshold('cpu', 90.0, 'gt', 60, 'CPU high');
        $firing = new AlertFiring(
            threshold: $threshold,
            actualValue: 95.5,
            firedAt: 1710000000.123,
        );

        self::assertSame($threshold, $firing->threshold);
        self::assertSame(95.5, $firing->actualValue);
        self::assertSame(1710000000.123, $firing->firedAt);
    }

    #[Test]
    public function fromFactoryCreatesWithCurrentTimestamp(): void
    {
        $threshold = new AlertThreshold('memory', 80.0, 'gt', 120);
        $before = microtime(true);

        $firing = AlertFiring::from($threshold, 85.0);

        $after = microtime(true);

        self::assertSame($threshold, $firing->threshold);
        self::assertSame(85.0, $firing->actualValue);
        self::assertGreaterThanOrEqual($before, $firing->firedAt);
        self::assertLessThanOrEqual($after, $firing->firedAt);
    }

    #[Test]
    public function fromPreservesThresholdReference(): void
    {
        $threshold = new AlertThreshold('latency', 200.0, 'gt', 30, 'Latency spike');

        $firing = AlertFiring::from($threshold, 350.0);

        self::assertSame('latency', $firing->threshold->metricName);
        self::assertSame('Latency spike', $firing->threshold->description);
    }
}
