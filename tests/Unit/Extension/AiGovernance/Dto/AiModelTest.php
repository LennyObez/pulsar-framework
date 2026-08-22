<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\AiGovernance\Dto;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;

#[CoversClass(AiModel::class)]
final class AiModelTest extends TestCase
{
    #[Test]
    public function withStatusReturnsNewInstanceWithDifferentStatus(): void
    {
        $model = $this->makeModel(status: AiModelStatus::Development);

        $updated = $model->withStatus(AiModelStatus::Production);

        self::assertSame(AiModelStatus::Production, $updated->status);
        self::assertSame(AiModelStatus::Development, $model->status);
        self::assertSame($model->id, $updated->id);
    }

    #[Test]
    public function withRiskLevelReturnsNewInstanceWithDifferentRiskLevel(): void
    {
        $model = $this->makeModel(riskLevel: AiModelRiskLevel::Minimal);

        $updated = $model->withRiskLevel(AiModelRiskLevel::High);

        self::assertSame(AiModelRiskLevel::High, $updated->riskLevel);
        self::assertSame(AiModelRiskLevel::Minimal, $model->riskLevel);
    }

    #[Test]
    public function withCardAttachesModelCard(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->card);

        $card = new ModelCard(
            description: 'Test model',
            intendedUse: 'Unit testing',
        );
        $updated = $model->withCard($card);

        self::assertSame($card, $updated->card);
        self::assertNull($model->card);
    }

    #[Test]
    public function withStatusPreservesAllOtherProperties(): void
    {
        $card = new ModelCard(description: 'desc', intendedUse: 'use');
        $registeredAt = new DateTimeImmutable('2025-01-01');
        $model = new AiModel(
            id: 'test-id',
            name: 'Test Model',
            version: '1.0',
            provider: 'Test Corp',
            type: 'classifier',
            riskLevel: AiModelRiskLevel::High,
            status: AiModelStatus::Testing,
            registeredAt: $registeredAt,
            card: $card,
        );

        $updated = $model->withStatus(AiModelStatus::Production);

        self::assertSame('test-id', $updated->id);
        self::assertSame('Test Model', $updated->name);
        self::assertSame('1.0', $updated->version);
        self::assertSame('Test Corp', $updated->provider);
        self::assertSame('classifier', $updated->type);
        self::assertSame(AiModelRiskLevel::High, $updated->riskLevel);
        self::assertSame($registeredAt, $updated->registeredAt);
        self::assertSame($card, $updated->card);
    }

    private function makeModel(
        AiModelStatus $status = AiModelStatus::Development,
        AiModelRiskLevel $riskLevel = AiModelRiskLevel::Minimal,
    ): AiModel {
        return new AiModel(
            id: 'test-model',
            name: 'Test',
            version: '1.0',
            provider: 'Test',
            type: 'llm',
            riskLevel: $riskLevel,
            status: $status,
            registeredAt: new DateTimeImmutable(),
        );
    }
}
