<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Internal\Gate;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ImpactFinding;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;
use Pulsar\Extension\AiGovernance\Internal\Gate\ImpactAssessmentGate;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryImpactAssessmentStore;

#[CoversClass(ImpactAssessmentGate::class)]
final class ImpactAssessmentGateTest extends TestCase
{
    #[Test]
    public function failsWhenModelHasNeverBeenAssessed(): void
    {
        $gate = new ImpactAssessmentGate(new InMemoryImpactAssessmentStore(), 7.0);

        self::assertFalse($gate->evaluate($this->model()));
        self::assertNotSame('', $gate->failureReason());
    }

    #[Test]
    public function passesWhenAssessedWithRiskAtOrBelowThreshold(): void
    {
        $store = new InMemoryImpactAssessmentStore();
        $store->assess('m1'); // assessed, no adverse findings -> score 0.0

        $gate = new ImpactAssessmentGate($store, 7.0);

        self::assertTrue($gate->evaluate($this->model()));
    }

    #[Test]
    public function failsWhenAssessedButRiskExceedsThreshold(): void
    {
        $store = new InMemoryImpactAssessmentStore();
        $store->addFinding('m1', new ImpactFinding(
            id: 'f1',
            category: ImpactCategory::Safety,
            severity: ImpactSeverity::Critical,
            title: 'Critical safety risk',
            description: 'Unmitigated critical risk',
            recommendation: 'Do not deploy',
        ));

        $gate = new ImpactAssessmentGate($store, 7.0);

        self::assertFalse($gate->evaluate($this->model()), 'critical finding scores 10.0, above 7.0 threshold');
    }

    #[Test]
    public function thresholdIsConfigurable(): void
    {
        $store = new InMemoryImpactAssessmentStore();
        $store->addFinding('m1', new ImpactFinding(
            id: 'f1',
            category: ImpactCategory::Safety,
            severity: ImpactSeverity::Critical,
            title: 'Critical safety risk',
            description: 'Unmitigated critical risk',
            recommendation: 'Mitigate',
        ));

        // A permissive threshold of 10.0 accepts the maximum score.
        self::assertTrue(new ImpactAssessmentGate($store, 10.0)->evaluate($this->model()));
    }

    private function model(): AiModel
    {
        return new AiModel(
            id: 'm1',
            name: 'Test Model',
            version: '1.0.0',
            provider: 'test',
            type: 'llm',
            riskLevel: AiModelRiskLevel::High,
            status: AiModelStatus::Staging,
            registeredAt: new DateTimeImmutable('2026-01-01'),
        );
    }
}
