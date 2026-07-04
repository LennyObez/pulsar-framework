<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Internal\Gate;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Internal\Gate\ModelCardGate;

#[CoversClass(ModelCardGate::class)]
final class ModelCardGateTest extends TestCase
{
    #[Test]
    public function passesWhenModelHasACard(): void
    {
        $gate = new ModelCardGate();
        $model = $this->model()->withCard(new ModelCard(description: 'A model', intendedUse: 'classification'));

        self::assertTrue($gate->evaluate($model));
    }

    #[Test]
    public function failsWhenModelHasNoCard(): void
    {
        $gate = new ModelCardGate();

        self::assertFalse($gate->evaluate($this->model()));
        self::assertNotSame('', $gate->failureReason());
    }

    #[Test]
    public function hasAStableName(): void
    {
        self::assertSame('model_card', new ModelCardGate()->name());
    }

    private function model(): AiModel
    {
        return new AiModel(
            id: 'm1',
            name: 'Test Model',
            version: '1.0.0',
            provider: 'test',
            type: 'llm',
            riskLevel: AiModelRiskLevel::Limited,
            status: AiModelStatus::Staging,
            registeredAt: new DateTimeImmutable('2026-01-01'),
        );
    }
}
