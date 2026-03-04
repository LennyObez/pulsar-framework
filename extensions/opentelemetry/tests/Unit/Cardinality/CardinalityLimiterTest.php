<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Cardinality;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Cardinality\CardinalityLimiter;
use Pulsar\Observability\Metrics\LabelSet;

#[CoversClass(CardinalityLimiter::class)]
final class CardinalityLimiterTest extends TestCase
{
    #[Test]
    public function guardReturnsOriginalLabelsWithinLimit(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 100);
        $labels = new LabelSet(['method' => 'GET']);

        $result = $limiter->guard('http_requests', $labels);

        self::assertSame($labels, $result);
    }

    #[Test]
    public function guardReturnsOverflowWhenLimitExceeded(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 2);

        // Fill to capacity
        $limiter->guard('metric', new LabelSet(['key' => 'val1']));
        $limiter->guard('metric', new LabelSet(['key' => 'val2']));

        // Third unique series exceeds limit
        $result = $limiter->guard('metric', new LabelSet(['key' => 'val3']));

        self::assertSame(['__overflow' => 'true'], $result->toArray());
    }

    #[Test]
    public function guardAllowsKnownSeriesEvenAfterLimit(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 2);
        $existing = new LabelSet(['key' => 'val1']);

        $limiter->guard('metric', $existing);
        $limiter->guard('metric', new LabelSet(['key' => 'val2']));

        // Re-guard an existing series is allowed
        $result = $limiter->guard('metric', $existing);
        self::assertSame($existing, $result);
    }

    #[Test]
    public function guardTracksSeparateMetrics(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 1);

        $limiter->guard('metric_a', new LabelSet(['k' => 'v']));
        $limiter->guard('metric_b', new LabelSet(['k' => 'v']));

        // Each metric has its own budget
        self::assertSame(
            ['__overflow' => 'true'],
            $limiter->guard('metric_a', new LabelSet(['k' => 'v2']))->toArray(),
        );
        self::assertSame(
            ['__overflow' => 'true'],
            $limiter->guard('metric_b', new LabelSet(['k' => 'v2']))->toArray(),
        );
    }

    #[Test]
    public function resetClearsAllTrackedSeries(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 1);

        $limiter->guard('metric', new LabelSet(['k' => 'v']));
        // Overflow
        self::assertSame(
            ['__overflow' => 'true'],
            $limiter->guard('metric', new LabelSet(['k' => 'v2']))->toArray(),
        );

        // Reset allows new series again
        $limiter->reset();
        $newLabels = new LabelSet(['k' => 'v3']);
        $result = $limiter->guard('metric', $newLabels);
        self::assertSame($newLabels, $result);
    }

    #[Test]
    public function emptyLabelSetIsTrackedAsOneSeries(): void
    {
        $limiter = new CardinalityLimiter(maxMetricSeries: 1);

        $empty = new LabelSet();
        $result = $limiter->guard('metric', $empty);
        self::assertSame($empty, $result);

        // Second unique set overflows
        self::assertSame(
            ['__overflow' => 'true'],
            $limiter->guard('metric', new LabelSet(['k' => 'v']))->toArray(),
        );
    }
}
