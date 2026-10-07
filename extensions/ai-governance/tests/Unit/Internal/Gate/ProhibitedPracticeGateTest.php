<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Internal\Gate;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Internal\Gate\ProhibitedPracticeGate;

#[CoversClass(ProhibitedPracticeGate::class)]
final class ProhibitedPracticeGateTest extends TestCase
{
    #[Test]
    public function refusesAModelClassifiedUnacceptable(): void
    {
        $gate = new ProhibitedPracticeGate();

        self::assertFalse($gate->evaluate($this->model(AiModelRiskLevel::Unacceptable)));
    }

    #[Test]
    public function namesArticle5InItsRefusal(): void
    {
        $gate = new ProhibitedPracticeGate();

        self::assertStringContainsString('Article 5', $gate->failureReason());
        self::assertStringContainsString('2024/1689', $gate->failureReason());
    }

    #[Test]
    #[DataProvider('permittedTiers')]
    public function passesEveryTierTheActPermits(AiModelRiskLevel $riskLevel): void
    {
        $gate = new ProhibitedPracticeGate();

        self::assertTrue($gate->evaluate($this->model($riskLevel)));
    }

    /**
     * @return iterable<string, array{AiModelRiskLevel}>
     */
    public static function permittedTiers(): iterable
    {
        yield 'minimal' => [AiModelRiskLevel::Minimal];
        yield 'limited' => [AiModelRiskLevel::Limited];
        yield 'high' => [AiModelRiskLevel::High];
    }

    #[Test]
    public function isNamedSoTheAuditRecordIdentifiesIt(): void
    {
        self::assertSame('prohibited_practice', new ProhibitedPracticeGate()->name());
    }

    private function model(AiModelRiskLevel $riskLevel): AiModel
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
        );
    }
}
