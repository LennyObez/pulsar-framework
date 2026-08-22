<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\DataProvenance;
use Pulsar\Extension\AiGovernance\Dto\DataQualityReport;
use Pulsar\Extension\AiGovernance\Dto\DecisionFactor;
use Pulsar\Extension\AiGovernance\Dto\Explanation;
use Pulsar\Extension\AiGovernance\Dto\ImpactFinding;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;

#[CoversClass(AiModel::class)]
#[CoversClass(ModelCard::class)]
#[CoversClass(DataProvenance::class)]
#[CoversClass(DataQualityReport::class)]
#[CoversClass(DecisionFactor::class)]
#[CoversClass(Explanation::class)]
#[CoversClass(ImpactFinding::class)]
final class DtoTest extends TestCase
{
    public function testAiModelWithStatus(): void
    {
        $model = new AiModel(
            id: 'm1',
            name: 'GPT-Test',
            version: '1.0',
            provider: 'openai',
            type: 'llm',
            riskLevel: AiModelRiskLevel::Limited,
            status: AiModelStatus::Development,
            registeredAt: new DateTimeImmutable('2026-01-01'),
        );

        $updated = $model->withStatus(AiModelStatus::Testing);
        self::assertSame(AiModelStatus::Testing, $updated->status);
        self::assertSame(AiModelStatus::Development, $model->status);
        self::assertSame('m1', $updated->id);
    }

    public function testAiModelWithRiskLevel(): void
    {
        $model = new AiModel(
            id: 'm1',
            name: 'Test',
            version: '1.0',
            provider: 'test',
            type: 'classifier',
            riskLevel: AiModelRiskLevel::Minimal,
            status: AiModelStatus::Development,
            registeredAt: new DateTimeImmutable(),
        );

        $updated = $model->withRiskLevel(AiModelRiskLevel::High);
        self::assertSame(AiModelRiskLevel::High, $updated->riskLevel);
        self::assertSame(AiModelRiskLevel::Minimal, $model->riskLevel);
    }

    public function testAiModelWithCard(): void
    {
        $model = new AiModel(
            id: 'm1',
            name: 'Test',
            version: '1.0',
            provider: 'test',
            type: 'llm',
            riskLevel: AiModelRiskLevel::Limited,
            status: AiModelStatus::Development,
            registeredAt: new DateTimeImmutable(),
        );

        self::assertNull($model->card);

        $card = new ModelCard(
            description: 'A test model',
            intendedUse: 'Testing purposes only',
            capabilities: ['text generation'],
            limitations: ['English only'],
            knownBiases: ['Western cultural bias'],
        );

        $updated = $model->withCard($card);
        self::assertNotNull($updated->card);
        self::assertSame('A test model', $updated->card->description);
        self::assertNull($model->card);
    }

    public function testModelCardProperties(): void
    {
        $card = new ModelCard(
            description: 'Classifier model',
            intendedUse: 'Fraud detection',
            capabilities: ['transaction classification'],
            limitations: ['Only handles USD'],
            knownBiases: ['Regional bias'],
            trainingDataSources: ['Internal transaction logs'],
            performanceMetrics: ['accuracy' => 0.98, 'f1' => 0.96],
            ethicalConsiderations: ['May disadvantage new customers'],
        );

        self::assertSame('Fraud detection', $card->intendedUse);
        self::assertCount(1, $card->capabilities);
        self::assertSame(0.98, $card->performanceMetrics['accuracy']);
    }

    public function testDataProvenanceProperties(): void
    {
        $provenance = new DataProvenance(
            id: 'prov-1',
            datasetId: 'ds-1',
            source: 'https://data.example.com',
            dataType: 'structured',
            collectedAt: new DateTimeImmutable('2025-06-15'),
            consentObtained: true,
            consentReference: 'consent-record-42',
            license: 'CC-BY-4.0',
            transformations: ['anonymized', 'normalized'],
            qualityMetrics: ['completeness' => 0.95],
        );

        self::assertSame('prov-1', $provenance->id);
        self::assertTrue($provenance->consentObtained);
        self::assertSame('consent-record-42', $provenance->consentReference);
        self::assertSame('CC-BY-4.0', $provenance->license);
        self::assertCount(2, $provenance->transformations);
    }

    public function testDataQualityReportOverallScore(): void
    {
        $report = new DataQualityReport(
            datasetId: 'ds-1',
            assessedAt: new DateTimeImmutable(),
            completeness: 90.0,
            accuracy: 85.0,
            consistency: 95.0,
            totalRecords: 5000,
            invalidRecords: 100,
        );

        // (90 + 85 + 95) / 3 = 90.0
        self::assertSame(90.0, $report->overallScore());
    }

    public function testDataQualityReportOverallScoreRounding(): void
    {
        $report = new DataQualityReport(
            datasetId: 'ds-1',
            assessedAt: new DateTimeImmutable(),
            completeness: 33.33,
            accuracy: 33.33,
            consistency: 33.34,
            totalRecords: 100,
            invalidRecords: 0,
        );

        // (33.33 + 33.33 + 33.34) / 3 = 33.333... → 33.33
        self::assertSame(33.33, $report->overallScore());
    }

    public function testImpactFindingProperties(): void
    {
        $finding = new ImpactFinding(
            id: 'f1',
            category: ImpactCategory::Privacy,
            severity: ImpactSeverity::High,
            title: 'PII exposure risk',
            description: 'Model may memorize PII from training data',
            recommendation: 'Apply differential privacy to training pipeline',
        );

        self::assertSame(ImpactCategory::Privacy, $finding->category);
        self::assertSame(ImpactSeverity::High, $finding->severity);
        self::assertSame('PII exposure risk', $finding->title);
    }

    public function testExplanationProperties(): void
    {
        $explanation = new Explanation(
            decisionId: 'dec-1',
            modelId: 'model-1',
            summary: 'Approved based on credit score',
            factors: [
                new DecisionFactor('credit_score', 0.7, 'Score above threshold'),
                new DecisionFactor('income_ratio', 0.3, 'Debt-to-income within limits'),
            ],
            confidence: 0.92,
            generatedAt: new DateTimeImmutable(),
            alternativesConsidered: ['deny', 'manual_review'],
        );

        self::assertCount(2, $explanation->factors);
        self::assertSame(0.92, $explanation->confidence);
        self::assertCount(2, $explanation->alternativesConsidered);
    }

    public function testDecisionFactorProperties(): void
    {
        $factor = new DecisionFactor(
            name: 'temperature',
            weight: 0.6,
            description: 'Operating temperature within safe range',
        );

        self::assertSame('temperature', $factor->name);
        self::assertSame(0.6, $factor->weight);
    }
}
