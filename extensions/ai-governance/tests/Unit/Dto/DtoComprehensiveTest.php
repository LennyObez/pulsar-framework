<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Dto;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringResult;
use Pulsar\Extension\AiGovernance\Dto\DataProvenance;
use Pulsar\Extension\AiGovernance\Dto\DecisionFactor;
use Pulsar\Extension\AiGovernance\Dto\Explanation;
use Pulsar\Extension\AiGovernance\Dto\ImpactFinding;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;

#[CoversClass(DataProvenance::class)]
#[CoversClass(DecisionFactor::class)]
#[CoversClass(Explanation::class)]
#[CoversClass(ImpactFinding::class)]
#[CoversClass(ModelCard::class)]
#[CoversClass(MonitoringResult::class)]
final class DtoComprehensiveTest extends TestCase
{
    // --- DataProvenance ---

    #[Test]
    public function dataProvenanceMinimalConstructor(): void
    {
        $collected = new DateTimeImmutable('2026-01-15');
        $provenance = new DataProvenance(
            id: 'DP-001',
            datasetId: 'DS-100',
            source: 'https://data.example.org/train',
            dataType: 'text',
            collectedAt: $collected,
        );

        self::assertSame('DP-001', $provenance->id);
        self::assertSame('DS-100', $provenance->datasetId);
        self::assertSame('https://data.example.org/train', $provenance->source);
        self::assertSame('text', $provenance->dataType);
        self::assertSame($collected, $provenance->collectedAt);
        self::assertFalse($provenance->consentObtained);
        self::assertNull($provenance->consentReference);
        self::assertNull($provenance->license);
        self::assertSame([], $provenance->transformations);
        self::assertSame([], $provenance->qualityMetrics);
    }

    #[Test]
    public function dataProvenanceFullConstructor(): void
    {
        $provenance = new DataProvenance(
            id: 'DP-002',
            datasetId: 'DS-200',
            source: 'internal-warehouse',
            dataType: 'images',
            collectedAt: new DateTimeImmutable('2025-06-01'),
            consentObtained: true,
            consentReference: 'CONSENT-2025-042',
            license: 'CC-BY-4.0',
            transformations: ['resize', 'normalize', 'augment'],
            qualityMetrics: ['accuracy' => 0.95, 'completeness' => 0.99],
        );

        self::assertTrue($provenance->consentObtained);
        self::assertSame('CONSENT-2025-042', $provenance->consentReference);
        self::assertSame('CC-BY-4.0', $provenance->license);
        self::assertCount(3, $provenance->transformations);
        self::assertSame(0.95, $provenance->qualityMetrics['accuracy']);
    }

    // --- DecisionFactor ---

    #[Test]
    public function decisionFactorConstructor(): void
    {
        $factor = new DecisionFactor(
            name: 'credit_score',
            weight: 0.45,
            description: 'Applicant credit score is a primary factor',
        );

        self::assertSame('credit_score', $factor->name);
        self::assertSame(0.45, $factor->weight);
        self::assertSame('Applicant credit score is a primary factor', $factor->description);
    }

    #[Test]
    public function decisionFactorWeightBoundary(): void
    {
        $zero = new DecisionFactor('a', 0.0, 'No influence');
        $full = new DecisionFactor('b', 1.0, 'Full influence');

        self::assertSame(0.0, $zero->weight);
        self::assertSame(1.0, $full->weight);
    }

    // --- Explanation ---

    #[Test]
    public function explanationMinimalConstructor(): void
    {
        $factor = new DecisionFactor('income', 0.7, 'Primary factor');
        $explanation = new Explanation(
            decisionId: 'DEC-001',
            modelId: 'model-gpt-4',
            summary: 'Application approved based on income and history',
            factors: [$factor],
            confidence: 0.92,
            generatedAt: new DateTimeImmutable('2026-03-15T14:30:00Z'),
        );

        self::assertSame('DEC-001', $explanation->decisionId);
        self::assertSame('model-gpt-4', $explanation->modelId);
        self::assertSame(0.92, $explanation->confidence);
        self::assertCount(1, $explanation->factors);
        self::assertSame([], $explanation->alternativesConsidered);
    }

    #[Test]
    public function explanationWithAlternatives(): void
    {
        $explanation = new Explanation(
            decisionId: 'DEC-002',
            modelId: 'model-v2',
            summary: 'Risk classified as low',
            factors: [],
            confidence: 0.85,
            generatedAt: new DateTimeImmutable(),
            alternativesConsidered: ['medium_risk', 'high_risk'],
        );

        self::assertCount(2, $explanation->alternativesConsidered);
        self::assertSame('medium_risk', $explanation->alternativesConsidered[0]);
    }

    // --- ImpactFinding ---

    #[Test]
    public function impactFindingConstructor(): void
    {
        $finding = new ImpactFinding(
            id: 'IF-001',
            category: ImpactCategory::Fairness,
            severity: ImpactSeverity::High,
            title: 'Potential bias in hiring model',
            description: 'The model shows disparate impact on protected groups',
            recommendation: 'Retrain with balanced dataset and add fairness constraints',
        );

        self::assertSame('IF-001', $finding->id);
        self::assertSame(ImpactCategory::Fairness, $finding->category);
        self::assertSame(ImpactSeverity::High, $finding->severity);
        self::assertSame('Potential bias in hiring model', $finding->title);
        self::assertStringContainsString('disparate impact', $finding->description);
        self::assertStringContainsString('Retrain', $finding->recommendation);
    }

    // --- ModelCard ---

    #[Test]
    public function modelCardMinimalConstructor(): void
    {
        $card = new ModelCard(
            description: 'Text classification model',
            intendedUse: 'Customer support ticket routing',
        );

        self::assertSame('Text classification model', $card->description);
        self::assertSame('Customer support ticket routing', $card->intendedUse);
        self::assertSame([], $card->capabilities);
        self::assertSame([], $card->limitations);
        self::assertSame([], $card->knownBiases);
        self::assertSame([], $card->trainingDataSources);
        self::assertSame([], $card->performanceMetrics);
        self::assertSame([], $card->ethicalConsiderations);
    }

    #[Test]
    public function modelCardFullConstructor(): void
    {
        $card = new ModelCard(
            description: 'Fraud detection neural network',
            intendedUse: 'Real-time transaction fraud screening',
            capabilities: ['Pattern recognition', 'Anomaly detection'],
            limitations: ['High-value transactions may have higher false positive rate'],
            knownBiases: ['Slightly higher false positive rate for international transactions'],
            trainingDataSources: ['Internal transaction data 2020-2025'],
            performanceMetrics: ['precision' => 0.98, 'recall' => 0.95, 'f1_score' => 0.965],
            ethicalConsiderations: ['Must not discriminate based on geographic origin'],
        );

        self::assertCount(2, $card->capabilities);
        self::assertCount(1, $card->limitations);
        self::assertCount(1, $card->knownBiases);
        self::assertSame(0.98, $card->performanceMetrics['precision']);
        self::assertCount(1, $card->ethicalConsiderations);
    }

    // --- MonitoringResult ---

    #[Test]
    public function monitoringResultHealthy(): void
    {
        $result = new MonitoringResult(
            healthy: true,
            hookName: 'drift_detector',
            message: 'Model performance within acceptable bounds',
        );

        self::assertTrue($result->healthy);
        self::assertSame('drift_detector', $result->hookName);
        self::assertSame('Model performance within acceptable bounds', $result->message);
        self::assertSame([], $result->metrics);
    }

    #[Test]
    public function monitoringResultUnhealthyWithMetrics(): void
    {
        $result = new MonitoringResult(
            healthy: false,
            hookName: 'accuracy_monitor',
            message: 'Accuracy dropped below threshold',
            metrics: ['accuracy' => 0.72, 'threshold' => 0.85, 'degradation_pct' => 15.3],
        );

        self::assertFalse($result->healthy);
        self::assertSame(0.72, $result->metrics['accuracy']);
        self::assertSame(15.3, $result->metrics['degradation_pct']);
    }
}
