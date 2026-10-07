<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\ABTest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\ABTest\ExperimentResult;

#[CoversClass(ExperimentResult::class)]
final class ExperimentResultTest extends TestCase
{
    #[Test]
    public function stores_all_metrics(): void
    {
        $result = new ExperimentResult(
            variantId: 'var-1',
            variantName: 'Variant A',
            impressions: 1000,
            conversions: 50,
            conversionRate: 5.0,
            confidenceLevel: 95.0,
        );

        self::assertSame('var-1', $result->variantId);
        self::assertSame('Variant A', $result->variantName);
        self::assertSame(1000, $result->impressions);
        self::assertSame(50, $result->conversions);
        self::assertSame(5.0, $result->conversionRate);
        self::assertSame(95.0, $result->confidenceLevel);
    }

    #[Test]
    public function zero_conversions_result(): void
    {
        $result = new ExperimentResult(
            variantId: 'var-2',
            variantName: 'Control',
            impressions: 500,
            conversions: 0,
            conversionRate: 0.0,
            confidenceLevel: 0.0,
        );

        self::assertSame(0, $result->conversions);
        self::assertSame(0.0, $result->conversionRate);
    }
}
