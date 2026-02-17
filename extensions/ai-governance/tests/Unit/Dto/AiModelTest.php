<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Dto;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;

#[CoversClass(AiModel::class)]
#[CoversClass(ModelCard::class)]
final class AiModelTest extends TestCase
{
    private function createModel(
        AiModelStatus $status = AiModelStatus::Development,
        AiModelRiskLevel $riskLevel = AiModelRiskLevel::Minimal,
    ): AiModel {
        return new AiModel(
            id: 'model-1',
            name: 'Fraud Detector',
            version: '2.0.0',
            provider: 'Anthropic',
            type: 'classifier',
            riskLevel: $riskLevel,
            status: $status,
            registeredAt: new DateTimeImmutable('2026-01-01'),
        );
    }

    #[Test]
    public function withStatusReturnsNewInstance(): void
    {
        $original = $this->createModel(AiModelStatus::Development);

        $updated = $original->withStatus(AiModelStatus::Production);

        self::assertSame(AiModelStatus::Development, $original->status);
        self::assertSame(AiModelStatus::Production, $updated->status);
        self::assertSame('model-1', $updated->id);
        self::assertSame('Fraud Detector', $updated->name);
    }

    #[Test]
    public function withRiskLevelReturnsNewInstance(): void
    {
        $original = $this->createModel(riskLevel: AiModelRiskLevel::Minimal);

        $updated = $original->withRiskLevel(AiModelRiskLevel::High);

        self::assertSame(AiModelRiskLevel::Minimal, $original->riskLevel);
        self::assertSame(AiModelRiskLevel::High, $updated->riskLevel);
        self::assertSame('model-1', $updated->id);
    }

    #[Test]
    public function withCardAttachesModelCard(): void
    {
        $model = $this->createModel();
        self::assertNull($model->card);

        $card = new ModelCard(
            description: 'Detects fraudulent transactions',
            intendedUse: 'Production fraud screening',
            capabilities: ['Real-time scoring', 'Batch processing'],
            limitations: ['English-language transactions only'],
            knownBiases: ['Higher false positive rate for small merchants'],
            trainingDataSources: ['Internal transaction logs 2020-2025'],
            performanceMetrics: ['f1_score' => 0.95, 'precision' => 0.97],
            ethicalConsiderations: ['May impact small business cash flow'],
        );

        $updated = $model->withCard($card);

        self::assertNull($model->card);
        self::assertNotNull($updated->card);
        self::assertSame('Detects fraudulent transactions', $updated->card->description);
        self::assertSame('Production fraud screening', $updated->card->intendedUse);
        self::assertCount(2, $updated->card->capabilities);
        self::assertCount(1, $updated->card->limitations);
        self::assertCount(1, $updated->card->knownBiases);
        self::assertCount(1, $updated->card->trainingDataSources);
        self::assertSame(0.95, $updated->card->performanceMetrics['f1_score']);
        self::assertCount(1, $updated->card->ethicalConsiderations);
    }

    #[Test]
    public function modelCardDefaultsToEmptyArrays(): void
    {
        $card = new ModelCard(
            description: 'Test model',
            intendedUse: 'Testing only',
        );

        self::assertSame([], $card->capabilities);
        self::assertSame([], $card->limitations);
        self::assertSame([], $card->knownBiases);
        self::assertSame([], $card->trainingDataSources);
        self::assertSame([], $card->performanceMetrics);
        self::assertSame([], $card->ethicalConsiderations);
    }
}
