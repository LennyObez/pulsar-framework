<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Internal\Gate;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Internal\Gate\HighRiskObligationsGate;
use Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistry;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryImpactAssessmentStore;

#[CoversClass(HighRiskObligationsGate::class)]
final class HighRiskObligationsGateTest extends TestCase
{
    private InMemoryImpactAssessmentStore $assessments;
    private MonitoringHookRegistry $monitoringHooks;
    private HighRiskObligationsGate $gate;

    protected function setUp(): void
    {
        $this->assessments = new InMemoryImpactAssessmentStore();
        $this->monitoringHooks = new MonitoringHookRegistry();
        $this->gate = new HighRiskObligationsGate($this->assessments, $this->monitoringHooks);
    }

    #[Test]
    public function refusesAHighRiskModelCarryingNoneOfTheObligations(): void
    {
        self::assertFalse($this->gate->evaluate($this->model(AiModelRiskLevel::High)));

        $reason = $this->gate->failureReason();
        self::assertStringContainsString('Article 9', $reason);
        self::assertStringContainsString('Article 11', $reason);
        self::assertStringContainsString('Article 72', $reason);
    }

    #[Test]
    public function refusesAHighRiskModelWithNoImpactAssessment(): void
    {
        $this->monitoringHooks->add($this->hook());

        self::assertFalse($this->gate->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));

        $reason = $this->gate->failureReason();
        self::assertStringContainsString('Article 9', $reason);
        self::assertStringNotContainsString('Article 11', $reason);
        self::assertStringNotContainsString('Article 72', $reason);
    }

    #[Test]
    public function refusesAHighRiskModelWithNoTechnicalDocumentation(): void
    {
        $this->assessments->assess('model-1');
        $this->monitoringHooks->add($this->hook());

        self::assertFalse($this->gate->evaluate($this->model(AiModelRiskLevel::High)));

        $reason = $this->gate->failureReason();
        self::assertStringContainsString('Article 11', $reason);
        self::assertStringNotContainsString('Article 9,', $reason);
        self::assertStringNotContainsString('Article 72', $reason);
    }

    #[Test]
    public function refusesAHighRiskModelWithNoPostMarketMonitoring(): void
    {
        $this->assessments->assess('model-1');

        self::assertFalse($this->gate->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));

        $reason = $this->gate->failureReason();
        self::assertStringContainsString('Article 72', $reason);
        self::assertStringNotContainsString('Article 11', $reason);
    }

    #[Test]
    public function passesAHighRiskModelCarryingAllThree(): void
    {
        $this->assessments->assess('model-1');
        $this->monitoringHooks->add($this->hook());

        self::assertTrue($this->gate->evaluate($this->model(AiModelRiskLevel::High, card: $this->card())));
    }

    #[Test]
    #[DataProvider('tiersWithoutPreMarketObligations')]
    public function leavesEveryOtherTierAlone(AiModelRiskLevel $riskLevel): void
    {
        self::assertTrue($this->gate->evaluate($this->model($riskLevel)));
    }

    /**
     * @return iterable<string, array{AiModelRiskLevel}>
     */
    public static function tiersWithoutPreMarketObligations(): iterable
    {
        yield 'minimal' => [AiModelRiskLevel::Minimal];
        yield 'limited' => [AiModelRiskLevel::Limited];
        yield 'unacceptable, which the prohibition gate refuses instead' => [AiModelRiskLevel::Unacceptable];
    }

    #[Test]
    public function reportsTheWholeObligationSetBeforeAnyEvaluation(): void
    {
        $reason = $this->gate->failureReason();

        self::assertStringContainsString('Article 9', $reason);
        self::assertStringContainsString('Article 11', $reason);
        self::assertStringContainsString('Article 72', $reason);
    }

    #[Test]
    public function isNamedSoTheAuditRecordIdentifiesIt(): void
    {
        self::assertSame('high_risk_obligations', $this->gate->name());
    }

    private function hook(): MonitoringHookInterface
    {
        $hook = $this->createStub(MonitoringHookInterface::class);
        $hook->method('name')->willReturn('drift-check');

        return $hook;
    }

    private function card(): ModelCard
    {
        return new ModelCard(
            description: 'Credit scoring classifier.',
            intendedUse: 'Consumer creditworthiness assessment.',
        );
    }

    private function model(AiModelRiskLevel $riskLevel, ?ModelCard $card = null): AiModel
    {
        return new AiModel(
            id: 'model-1',
            name: 'Test Model',
            version: '1.0.0',
            provider: 'TestProvider',
            type: 'classifier',
            riskLevel: $riskLevel,
            status: AiModelStatus::Staging,
            registeredAt: new DateTimeImmutable('2026-01-01'),
            card: $card,
        );
    }
}
