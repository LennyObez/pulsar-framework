<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Tooling\Support\SampleStatistics;

/**
 * The benchmark gate decides whether a release regresses, and it decides on these
 * two numbers. An off-by-one in the even-count median or a spread that divides by
 * the wrong term would not crash anything — it would quietly publish a wrong verdict,
 * which is why they are tested rather than trusted.
 */
#[CoversClass(SampleStatistics::class)]
final class SampleStatisticsTest extends TestCase
{
    #[Test]
    public function medianOfAnOddCountIsTheMiddleValue(): void
    {
        self::assertSame(7, SampleStatistics::median([9, 7, 2]));
        self::assertSame(4, SampleStatistics::median([4]));
    }

    #[Test]
    public function medianOfAnEvenCountAveragesTheTwoMiddleValues(): void
    {
        self::assertSame(5, SampleStatistics::median([2, 4, 6, 8]));
        self::assertSame(3, SampleStatistics::median([1, 2, 4, 8]), 'integer division floors, deliberately');
    }

    #[Test]
    public function medianDoesNotDependOnInputOrder(): void
    {
        $ordered = SampleStatistics::median([1, 2, 3, 4, 5]);

        self::assertSame($ordered, SampleStatistics::median([5, 4, 3, 2, 1]));
        self::assertSame($ordered, SampleStatistics::median([3, 1, 5, 2, 4]));
    }

    /**
     * The point of preferring a median: one descheduled run must not move the centre.
     */
    #[Test]
    public function medianIgnoresASingleOutlierThatWouldMoveAMean(): void
    {
        $clean = [100, 101, 102, 103, 104];
        $withOutlier = [100, 101, 102, 103, 100_000];

        self::assertSame(102, SampleStatistics::median($clean));
        self::assertSame(102, SampleStatistics::median($withOutlier));
    }

    #[Test]
    public function medianOfNoSamplesIsZero(): void
    {
        self::assertSame(0, SampleStatistics::median([]));
    }

    #[Test]
    public function spreadIsTheFullRangeOverTheMedianAsAPercentage(): void
    {
        // range 20 over median 100
        self::assertSame(20.0, SampleStatistics::spreadPercent([90, 100, 110]));
        self::assertSame(0.0, SampleStatistics::spreadPercent([50, 50, 50]));
    }

    #[Test]
    public function spreadOfFewerThanTwoSamplesIsZeroBecauseItIsUnmeasured(): void
    {
        self::assertSame(0.0, SampleStatistics::spreadPercent([]));
        self::assertSame(0.0, SampleStatistics::spreadPercent([42]));
    }

    /**
     * A zero median would divide by zero; reporting 0.0 keeps the runner alive and
     * the caller's sample-count gate is what catches the degenerate case.
     */
    #[Test]
    public function spreadIsZeroWhenTheMedianIsZero(): void
    {
        self::assertSame(0.0, SampleStatistics::spreadPercent([0, 0, 0]));
    }

    #[Test]
    public function spreadRoundsToOneDecimal(): void
    {
        self::assertSame(6.7, SampleStatistics::spreadPercent([29, 30, 31]));
    }
}
