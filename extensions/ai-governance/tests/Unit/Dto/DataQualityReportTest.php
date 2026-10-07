<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Dto;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\DataProvenance;
use Pulsar\Extension\AiGovernance\Dto\DataQualityReport;
use Pulsar\Extension\AiGovernance\Dto\DecisionFactor;
use Pulsar\Extension\AiGovernance\Dto\Explanation;
use Pulsar\Extension\AiGovernance\Dto\ImpactFinding;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;

#[CoversClass(DataQualityReport::class)]
#[CoversClass(DataProvenance::class)]
#[CoversClass(DecisionFactor::class)]
#[CoversClass(Explanation::class)]
#[CoversClass(ImpactFinding::class)]
final class DataQualityReportTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float, float, float}>
     */
    public static function overallScoreProvider(): iterable
    {
        yield 'perfect scores' => [100.0, 100.0, 100.0, 100.0];
        yield 'zero scores' => [0.0, 0.0, 0.0, 0.0];
        yield 'mixed scores' => [90.0, 80.0, 70.0, 80.0];
        yield 'uneven scores' => [95.5, 85.3, 92.1, 90.97];
    }

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
            assessedAt: new DateTimeImmutable('2026-01-01'),
            completeness: $completeness,
            accuracy: $accuracy,
            consistency: $consistency,
            totalRecords: 10000,
            invalidRecords: 50,
        );

        self::assertSame($expectedScore, $report->overallScore());
    }

    #[Test]
    public function dataQualityReportWithIssues(): void
    {
        $report = new DataQualityReport(
            datasetId: 'ds-2',
            assessedAt: new DateTimeImmutable('2026-03-15'),
            completeness: 95.0,
            accuracy: 88.0,
            consistency: 92.0,
            totalRecords: 50000,
            invalidRecords: 2500,
            issues: ['Missing timestamps in 5% of records', 'Duplicate entries detected'],
        );

        self::assertSame('ds-2', $report->datasetId);
        self::assertSame(50000, $report->totalRecords);
        self::assertSame(2500, $report->invalidRecords);
        self::assertCount(2, $report->issues);
    }

    #[Test]
    public function dataProvenanceDefaults(): void
    {
        $provenance = new DataProvenance(
            id: 'prov-1',
            datasetId: 'ds-1',
            source: 'internal-db',
            dataType: 'structured',
            collectedAt: new DateTimeImmutable('2025-06-01'),
        );

        self::assertFalse($provenance->consentObtained);
        self::assertNull($provenance->consentReference);
        self::assertNull($provenance->license);
        self::assertSame([], $provenance->transformations);
        self::assertSame([], $provenance->qualityMetrics);
    }

    #[Test]
    public function dataProvenanceWithAllFields(): void
    {
        $provenance = new DataProvenance(
            id: 'prov-2',
            datasetId: 'ds-2',
            source: 'https://data.gov/dataset/123',
            dataType: 'text',
            collectedAt: new DateTimeImmutable('2025-01-01'),
            consentObtained: true,
            consentReference: 'CONSENT-2025-001',
            license: 'CC-BY-4.0',
            transformations: ['PII removal', 'Tokenization'],
            qualityMetrics: ['completeness' => 98.5],
        );

        self::assertTrue($provenance->consentObtained);
        self::assertSame('CONSENT-2025-001', $provenance->consentReference);
        self::assertSame('CC-BY-4.0', $provenance->license);
        self::assertCount(2, $provenance->transformations);
        self::assertSame(98.5, $provenance->qualityMetrics['completeness']);
    }

    #[Test]
    public function explanationWithFactors(): void
    {
        $factors = [
            new DecisionFactor('transaction_amount', 0.8, 'Unusually large amount'),
            new DecisionFactor('location', 0.15, 'Transaction from new country'),
            new DecisionFactor('frequency', 0.05, 'Normal transaction frequency'),
        ];

        $explanation = new Explanation(
            decisionId: 'dec-1',
            modelId: 'model-1',
            summary: 'Transaction flagged as potentially fraudulent due to amount and location.',
            factors: $factors,
            confidence: 0.92,
            generatedAt: new DateTimeImmutable('2026-03-15T10:00:00Z'),
            alternativesConsidered: ['Legitimate high-value purchase', 'Account takeover'],
        );

        self::assertSame('dec-1', $explanation->decisionId);
        self::assertSame(0.92, $explanation->confidence);
        self::assertCount(3, $explanation->factors);
        self::assertSame('transaction_amount', $explanation->factors[0]->name);
        self::assertSame(0.8, $explanation->factors[0]->weight);
        self::assertCount(2, $explanation->alternativesConsidered);
    }

    #[Test]
    public function impactFindingCarriesCategoryAndSeverity(): void
    {
        $finding = new ImpactFinding(
            id: 'IF-001',
            category: ImpactCategory::Fairness,
            severity: ImpactSeverity::High,
            title: 'Demographic bias detected',
            description: 'Model shows disparate impact across age groups.',
            recommendation: 'Retrain with balanced dataset or apply post-processing calibration.',
        );

        self::assertSame('IF-001', $finding->id);
        self::assertSame(ImpactCategory::Fairness, $finding->category);
        self::assertSame(ImpactSeverity::High, $finding->severity);
        self::assertSame('Demographic bias detected', $finding->title);
    }
}
