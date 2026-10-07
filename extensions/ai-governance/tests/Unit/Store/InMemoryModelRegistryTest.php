<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Store;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryModelRegistry;

use function restore_error_handler;
use function set_error_handler;

#[CoversClass(InMemoryModelRegistry::class)]
final class InMemoryModelRegistryTest extends TestCase
{
    private InMemoryModelRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new InMemoryModelRegistry();
    }

    #[Test]
    public function getReturnsNullForUnknownModel(): void
    {
        self::assertNull($this->registry->get('nonexistent'));
    }

    #[Test]
    public function registerAndGet(): void
    {
        $model = $this->createModel('m1');
        $this->registry->register($model);

        $found = $this->registry->get('m1');
        self::assertSame($model, $found);
    }

    #[Test]
    public function registerOverwritesPreviousModel(): void
    {
        $this->registry->register($this->createModel('m1'));
        $updated = $this->createModel('m1', status: AiModelStatus::Testing);
        $this->registry->register($updated);

        self::assertSame(AiModelStatus::Testing, $this->registry->get('m1')?->status);
    }

    #[Test]
    public function transitionStatusValidTransition(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Development));

        $updated = $this->registry->transitionStatus('m1', AiModelStatus::Testing);

        self::assertSame(AiModelStatus::Testing, $updated->status);
        self::assertSame(AiModelStatus::Testing, $this->registry->get('m1')?->status);
    }

    #[Test]
    public function transitionStatusTracksPreviousStatus(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Testing));
        $this->registry->transitionStatus('m1', AiModelStatus::Staging);

        self::assertSame(AiModelStatus::Testing, $this->registry->getPreviousStatus('m1'));
    }

    #[Test]
    public function transitionStatusThrowsOnInvalidTransition(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Development));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid status transition');

        $this->registry->transitionStatus('m1', AiModelStatus::Production);
    }

    #[Test]
    public function transitionStatusThrowsOnUnknownModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        $this->registry->transitionStatus('unknown', AiModelStatus::Testing);
    }

    #[Test]
    public function retiredModelCannotTransition(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Retired));

        $this->expectException(InvalidArgumentException::class);
        $this->registry->transitionStatus('m1', AiModelStatus::Development);
    }

    #[Test]
    public function updateRiskLevel(): void
    {
        $this->registry->register($this->createModel('m1'));

        $updated = $this->registry->updateRiskLevel('m1', AiModelRiskLevel::Unacceptable);

        self::assertSame(AiModelRiskLevel::Unacceptable, $updated->riskLevel);
        self::assertSame(AiModelRiskLevel::Unacceptable, $this->registry->get('m1')?->riskLevel);
    }

    #[Test]
    public function updateRiskLevelThrowsOnUnknownModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Cast rather than assign: the return is deliberately unused here, and
        // updateRiskLevel() is #[NoDiscard] because it can withdraw a live model.
        (void) $this->registry->updateRiskLevel('missing', AiModelRiskLevel::High);
    }

    #[Test]
    public function allReturnsAllModels(): void
    {
        self::assertSame([], $this->registry->all());

        $this->registry->register($this->createModel('m1'));
        $this->registry->register($this->createModel('m2'));

        self::assertCount(2, $this->registry->all());
    }

    #[Test]
    public function byStatusFiltersCorrectly(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Development));
        $this->registry->register($this->createModel('m2', status: AiModelStatus::Testing));
        $this->registry->register($this->createModel('m3', status: AiModelStatus::Development));

        $devModels = $this->registry->byStatus(AiModelStatus::Development);

        self::assertCount(2, $devModels);
        self::assertSame(AiModelStatus::Development, $devModels[0]->status);
        self::assertSame(AiModelStatus::Development, $devModels[1]->status);
    }

    #[Test]
    public function byRiskLevelFiltersCorrectly(): void
    {
        $this->registry->register($this->createModel('m1', riskLevel: AiModelRiskLevel::High));
        $this->registry->register($this->createModel('m2', riskLevel: AiModelRiskLevel::Minimal));
        $this->registry->register($this->createModel('m3', riskLevel: AiModelRiskLevel::High));

        $highRisk = $this->registry->byRiskLevel(AiModelRiskLevel::High);

        self::assertCount(2, $highRisk);
    }

    #[Test]
    public function byStatusReturnsEmptyWhenNoMatches(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Development));

        self::assertSame([], $this->registry->byStatus(AiModelStatus::Production));
    }

    #[Test]
    public function getPreviousStatusReturnsNullWhenNoTransition(): void
    {
        self::assertNull($this->registry->getPreviousStatus('m1'));
    }

    #[Test]
    public function fullLifecycleTransitionChain(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Development));
        $this->registry->transitionStatus('m1', AiModelStatus::Testing);
        $this->registry->transitionStatus('m1', AiModelStatus::Staging);
        $this->registry->transitionStatus('m1', AiModelStatus::Production);
        $this->registry->transitionStatus('m1', AiModelStatus::Deprecated);
        $this->registry->transitionStatus('m1', AiModelStatus::Retired);

        self::assertSame(AiModelStatus::Retired, $this->registry->get('m1')?->status);
    }

    #[Test]
    public function refusesToRegisterAProhibitedPracticeAlreadyInProduction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Article 5');

        $this->registry->register($this->createModel(
            'm1',
            status: AiModelStatus::Production,
            riskLevel: AiModelRiskLevel::Unacceptable,
        ));
    }

    /**
     * The refusal above is specific to Article 5 and must not generalise. An
     * inventory that would not record a running high-risk system could not
     * govern it, and the Act treats an unmet condition as a gap
     * rather than as a system that may not exist.
     */
    #[Test]
    public function recordsAHighRiskSystemThatIsAlreadyLiveWithItsObligationsUnmet(): void
    {
        $this->registry->register($this->createModel(
            'm1',
            status: AiModelStatus::Production,
            riskLevel: AiModelRiskLevel::High,
        ));

        self::assertSame(AiModelStatus::Production, $this->registry->get('m1')?->status);
        self::assertSame(AiModelRiskLevel::High, $this->registry->get('m1')?->riskLevel);
    }

    #[Test]
    public function refusesToTransitionAProhibitedPracticeIntoProduction(): void
    {
        $this->registry->register($this->createModel(
            'm1',
            status: AiModelStatus::Staging,
            riskLevel: AiModelRiskLevel::Unacceptable,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Article 5');

        $this->registry->transitionStatus('m1', AiModelStatus::Production);
    }

    #[Test]
    public function refusesToRestoreAProhibitedPracticeFromDeprecated(): void
    {
        $this->registry->register($this->createModel(
            'm1',
            status: AiModelStatus::Deprecated,
            riskLevel: AiModelRiskLevel::Unacceptable,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Article 5');

        $this->registry->transitionStatus('m1', AiModelStatus::Production);
    }

    #[Test]
    public function stillAllowsAPermittedModelIntoProduction(): void
    {
        $this->registry->register($this->createModel(
            'm1',
            status: AiModelStatus::Staging,
            riskLevel: AiModelRiskLevel::High,
        ));

        $updated = $this->registry->transitionStatus('m1', AiModelStatus::Production);

        self::assertSame(AiModelStatus::Production, $updated->status);
    }

    #[Test]
    public function reclassifyingALiveModelAsProhibitedWithdrawsItFromProduction(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Staging));
        $this->registry->transitionStatus('m1', AiModelStatus::Production);

        $updated = $this->registry->updateRiskLevel('m1', AiModelRiskLevel::Unacceptable);

        self::assertSame(AiModelRiskLevel::Unacceptable, $updated->riskLevel);
        self::assertSame(AiModelStatus::Deprecated, $updated->status);
        self::assertSame(AiModelStatus::Deprecated, $this->registry->get('m1')?->status);
        self::assertSame(AiModelStatus::Production, $this->registry->getPreviousStatus('m1'));
    }

    /**
     * The withdrawal is reported only through the return value, so dropping it
     * has to be diagnosed rather than silently allowed. PHP does not inherit
     * `#[NoDiscard]` from the interface, which is why the attribute sits on the
     * implementation and why this asserts the diagnostic instead of the
     * attribute.
     */
    #[Test]
    public function discardingTheReclassificationResultIsDiagnosed(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Staging));
        $this->registry->transitionStatus('m1', AiModelStatus::Production);

        $diagnostics = [];
        set_error_handler(static function (int $severity, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        try {
            $this->registry->updateRiskLevel('m1', AiModelRiskLevel::Unacceptable);
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $diagnostics);
        self::assertStringContainsString('updateRiskLevel', $diagnostics[0]);
    }

    #[Test]
    public function reclassifyingAModelThatIsNotLiveLeavesItsStatusAlone(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Staging));

        $updated = $this->registry->updateRiskLevel('m1', AiModelRiskLevel::Unacceptable);

        self::assertSame(AiModelRiskLevel::Unacceptable, $updated->riskLevel);
        self::assertSame(AiModelStatus::Staging, $updated->status);
    }

    #[Test]
    public function reclassifyingALiveModelAsPermittedLeavesItInProduction(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Staging));
        $this->registry->transitionStatus('m1', AiModelStatus::Production);

        $updated = $this->registry->updateRiskLevel('m1', AiModelRiskLevel::High);

        self::assertSame(AiModelStatus::Production, $updated->status);
    }

    private function createModel(
        string $id = 'model-1',
        AiModelStatus $status = AiModelStatus::Development,
        AiModelRiskLevel $riskLevel = AiModelRiskLevel::Limited,
    ): AiModel {
        return new AiModel(
            id: $id,
            name: "Test Model $id",
            version: '1.0.0',
            provider: 'TestProvider',
            type: 'classifier',
            riskLevel: $riskLevel,
            status: $status,
            registeredAt: new DateTimeImmutable('2026-01-01'),
        );
    }
}
