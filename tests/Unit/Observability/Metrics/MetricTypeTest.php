<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\MetricType;

#[CoversClass(MetricType::class)]
final class MetricTypeTest extends TestCase
{
    #[Test]
    public function casesHaveExpectedValues(): void
    {
        self::assertSame('counter', MetricType::Counter->value);
        self::assertSame('gauge', MetricType::Gauge->value);
        self::assertSame('histogram', MetricType::Histogram->value);
    }
}
