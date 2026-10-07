<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\AiGovernance\Dto;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\DataQualityReport;

#[CoversClass(DataQualityReport::class)]
final class DataQualityReportTest extends TestCase
{
    /**
     * @param float $completeness
     * @param float $accuracy
     * @param float $consistency
     * @param float $expectedScore
     */
    #[Test]
    #[DataProvider('overallScoreProvider')]
    public function overallScoreComputesWeightedAverage(
        float $completeness,
        float $accuracy,
        float $consistency,
        float $expectedScore,
    ): void {
        $report = new DataQualityReport(
            datasetId: 'ds-1',
            assessedAt: new DateTimeImmutable(),
            completeness: $completeness,
            accuracy: $accuracy,
            consistency: $consistency,
            totalRecords: 1000,
            invalidRecords: 0,
        );

        self::assertSame($expectedScore, $report->overallScore());
    }

    /**
     * @return iterable<string, array{float, float, float, float}>
     */
    public static function overallScoreProvider(): iterable
    {
        yield 'perfect scores' => [100.0, 100.0, 100.0, 100.0];
        yield 'all zeros' => [0.0, 0.0, 0.0, 0.0];
        yield 'mixed scores' => [90.0, 80.0, 70.0, 80.0];
        yield 'uneven scores' => [95.5, 88.3, 76.2, 86.67];
    }

    #[Test]
    public function issuesDefaultToEmpty(): void
    {
        $report = new DataQualityReport(
            datasetId: 'ds-1',
            assessedAt: new DateTimeImmutable(),
            completeness: 100.0,
            accuracy: 100.0,
            consistency: 100.0,
            totalRecords: 100,
            invalidRecords: 0,
        );

        self::assertSame([], $report->issues);
    }

    #[Test]
    public function preservesIssuesList(): void
    {
        $issues = ['Missing dates in 5% of records', 'Duplicate entries detected'];

        $report = new DataQualityReport(
            datasetId: 'ds-1',
            assessedAt: new DateTimeImmutable(),
            completeness: 85.0,
            accuracy: 90.0,
            consistency: 78.0,
            totalRecords: 5000,
            invalidRecords: 250,
            issues: $issues,
        );

        self::assertSame($issues, $report->issues);
        self::assertSame(5000, $report->totalRecords);
        self::assertSame(250, $report->invalidRecords);
    }
}
