<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Cardinality;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Tracing\Cardinality\CardinalityLimiter;
use Pulsar\Observability\Metrics\LabelSet;

#[CoversClass(CardinalityLimiter::class)]
final class CardinalityLimiterTest extends TestCase
{
    #[Test]
    public function allowsSeriesWithinLimit(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 3);

        $labels1 = new LabelSet(['method' => 'GET']);
        $labels2 = new LabelSet(['method' => 'POST']);
        $labels3 = new LabelSet(['method' => 'PUT']);

        self::assertSame($labels1, $limiter->guard('http_requests', $labels1));
        self::assertSame($labels2, $limiter->guard('http_requests', $labels2));
        self::assertSame($labels3, $limiter->guard('http_requests', $labels3));
    }

    #[Test]
    public function returnsOverflowBucketWhenExceeded(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 2);

        $limiter->guard('http_requests', new LabelSet(['method' => 'GET']));
        $limiter->guard('http_requests', new LabelSet(['method' => 'POST']));

        // Third unique label set exceeds limit
        $overflow = $limiter->guard('http_requests', new LabelSet(['method' => 'DELETE']));

        self::assertSame(['__overflow' => 'true'], $overflow->toArray());
    }

    #[Test]
    public function existingSeriesStillAllowedAfterLimit(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 1);

        $labels = new LabelSet(['method' => 'GET']);
        self::assertSame($labels, $limiter->guard('http_requests', $labels));

        // New series overflows
        $overflow = $limiter->guard('http_requests', new LabelSet(['method' => 'POST']));
        self::assertSame(['__overflow' => 'true'], $overflow->toArray());

        // Existing series still works
        self::assertSame($labels, $limiter->guard('http_requests', $labels));
    }

    #[Test]
    public function separateMetricsHaveIndependentLimits(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 1);

        $labelsA = new LabelSet(['method' => 'GET']);
        $labelsB = new LabelSet(['query' => 'SELECT']);

        // Each metric gets its own budget
        self::assertSame($labelsA, $limiter->guard('http_requests', $labelsA));
        self::assertSame($labelsB, $limiter->guard('db_queries', $labelsB));
    }

    #[Test]
    public function emptyLabelSetCountsAsOneSeries(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 1);

        $empty = new LabelSet();
        self::assertSame($empty, $limiter->guard('metric', $empty));

        // Another label set would overflow
        $overflow = $limiter->guard('metric', new LabelSet(['key' => 'value']));
        self::assertSame(['__overflow' => 'true'], $overflow->toArray());
    }

    #[Test]
    public function duplicateLabelSetsDoNotCountTwice(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 1);

        $labels = new LabelSet(['method' => 'GET']);
        $same = new LabelSet(['method' => 'GET']);

        self::assertSame($labels, $limiter->guard('http_requests', $labels));
        // Same label key: not a new series
        self::assertSame($same, $limiter->guard('http_requests', $same));
    }
}
