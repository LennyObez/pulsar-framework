<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\AiGovernance\Internal\Store;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryModelRegistry;

#[CoversClass(InMemoryModelRegistry::class)]
final class InMemoryModelRegistryTest extends TestCase
{
    private InMemoryModelRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new InMemoryModelRegistry();
    }

    #[Test]
    public function registerAndRetrieveModel(): void
    {
        $model = $this->makeModel('m-1');
        $this->registry->register($model);

        $retrieved = $this->registry->get('m-1');

        self::assertNotNull($retrieved);
        self::assertSame('m-1', $retrieved->id);
    }

    #[Test]
    public function getNonExistentModelReturnsNull(): void
    {
        self::assertNull($this->registry->get('nonexistent'));
    }

    #[Test]
    public function allReturnsAllRegisteredModels(): void
    {
        $this->registry->register($this->makeModel('m-1'));
        $this->registry->register($this->makeModel('m-2'));
        $this->registry->register($this->makeModel('m-3'));

        self::assertCount(3, $this->registry->all());
    }

    #[Test]
    public function allReturnsEmptyForFreshRegistry(): void
    {
        self::assertCount(0, $this->registry->all());
    }

    #[Test]
    public function byStatusFiltersCorrectly(): void
    {
        $this->registry->register($this->makeModel('m-dev', AiModelStatus::Development));
        $this->registry->register($this->makeModel('m-test', AiModelStatus::Testing));
        $this->registry->register($this->makeModel('m-dev2', AiModelStatus::Development));

        $devModels = $this->registry->byStatus(AiModelStatus::Development);

        self::assertCount(2, $devModels);
    }

    #[Test]
    public function byRiskLevelFiltersCorrectly(): void
    {
        $this->registry->register($this->makeModel('m-1', riskLevel: AiModelRiskLevel::High));
        $this->registry->register($this->makeModel('m-2', riskLevel: AiModelRiskLevel::Minimal));
        $this->registry->register($this->makeModel('m-3', riskLevel: AiModelRiskLevel::High));

        $highRisk = $this->registry->byRiskLevel(AiModelRiskLevel::High);

        self::assertCount(2, $highRisk);
    }

    /**
     * @param AiModelStatus $from
     * @param AiModelStatus $to
     */
    #[Test]
    #[DataProvider('validTransitionProvider')]
    public function validStatusTransitionSucceeds(AiModelStatus $from, AiModelStatus $to): void
    {
        $this->registry->register($this->makeModel('m-1', $from));

        $updated = $this->registry->transitionStatus('m-1', $to);

        self::assertSame($to, $updated->status);
        self::assertSame($to, $this->registry->get('m-1')?->status);
    }

    /**
     * @return iterable<string, array{AiModelStatus, AiModelStatus}>
     */
    public static function validTransitionProvider(): iterable
    {
        yield 'dev → testing' => [AiModelStatus::Development, AiModelStatus::Testing];
        yield 'dev → retired' => [AiModelStatus::Development, AiModelStatus::Retired];
        yield 'testing → staging' => [AiModelStatus::Testing, AiModelStatus::Staging];
        yield 'testing → dev' => [AiModelStatus::Testing, AiModelStatus::Development];
        yield 'testing → retired' => [AiModelStatus::Testing, AiModelStatus::Retired];
        yield 'staging → production' => [AiModelStatus::Staging, AiModelStatus::Production];
        yield 'staging → testing' => [AiModelStatus::Staging, AiModelStatus::Testing];
        yield 'staging → retired' => [AiModelStatus::Staging, AiModelStatus::Retired];
        yield 'production → deprecated' => [AiModelStatus::Production, AiModelStatus::Deprecated];
        yield 'production → staging' => [AiModelStatus::Production, AiModelStatus::Staging];
        yield 'deprecated → retired' => [AiModelStatus::Deprecated, AiModelStatus::Retired];
        yield 'deprecated → production' => [AiModelStatus::Deprecated, AiModelStatus::Production];
    }

    /**
     * @param AiModelStatus $from
     * @param AiModelStatus $to
     */
    #[Test]
    #[DataProvider('invalidTransitionProvider')]
    public function invalidStatusTransitionThrows(AiModelStatus $from, AiModelStatus $to): void
    {
        $this->registry->register($this->makeModel('m-1', $from));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid status transition/');

        $this->registry->transitionStatus('m-1', $to);
    }

    /**
     * @return iterable<string, array{AiModelStatus, AiModelStatus}>
     */
    public static function invalidTransitionProvider(): iterable
    {
        yield 'dev → production' => [AiModelStatus::Development, AiModelStatus::Production];
        yield 'dev → deprecated' => [AiModelStatus::Development, AiModelStatus::Deprecated];
        yield 'dev → staging' => [AiModelStatus::Development, AiModelStatus::Staging];
        yield 'production → development' => [AiModelStatus::Production, AiModelStatus::Development];
        yield 'production → testing' => [AiModelStatus::Production, AiModelStatus::Testing];
        yield 'production → retired' => [AiModelStatus::Production, AiModelStatus::Retired];
        yield 'retired → development' => [AiModelStatus::Retired, AiModelStatus::Development];
        yield 'retired → production' => [AiModelStatus::Retired, AiModelStatus::Production];
    }

    #[Test]
    public function transitionStatusOnNonExistentModelThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $this->registry->transitionStatus('ghost', AiModelStatus::Testing);
    }

    #[Test]
    public function updateRiskLevelChangesLevel(): void
    {
        $this->registry->register($this->makeModel('m-1', riskLevel: AiModelRiskLevel::Minimal));

        $updated = $this->registry->updateRiskLevel('m-1', AiModelRiskLevel::High);

        self::assertSame(AiModelRiskLevel::High, $updated->riskLevel);
        self::assertSame(AiModelRiskLevel::High, $this->registry->get('m-1')?->riskLevel);
    }

    #[Test]
    public function updateRiskLevelOnNonExistentModelThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry->updateRiskLevel('ghost', AiModelRiskLevel::High);
    }

    #[Test]
    public function transitionTracksPreviousStatus(): void
    {
        $this->registry->register($this->makeModel('m-1', AiModelStatus::Development));

        self::assertNull($this->registry->getPreviousStatus('m-1'));

        $this->registry->transitionStatus('m-1', AiModelStatus::Testing);

        self::assertSame(AiModelStatus::Development, $this->registry->getPreviousStatus('m-1'));
    }

    #[Test]
    public function registerOverwritesExistingModel(): void
    {
        $this->registry->register($this->makeModel('m-1', AiModelStatus::Development));
        $this->registry->register($this->makeModel('m-1', AiModelStatus::Testing));

        self::assertSame(AiModelStatus::Testing, $this->registry->get('m-1')?->status);
        self::assertCount(1, $this->registry->all());
    }

    #[Test]
    public function getPreviousStatusForUnknownModelReturnsNull(): void
    {
        self::assertNull($this->registry->getPreviousStatus('nonexistent'));
    }

    #[Test]
    public function byStatusReturnsEmptyWhenNoMatch(): void
    {
        $this->registry->register($this->makeModel('m-1', AiModelStatus::Development));

        self::assertCount(0, $this->registry->byStatus(AiModelStatus::Production));
    }

    /**
     * @param non-empty-string $id
     */
    private function makeModel(
        string $id = 'test-model',
        AiModelStatus $status = AiModelStatus::Development,
        AiModelRiskLevel $riskLevel = AiModelRiskLevel::Minimal,
    ): AiModel {
        return new AiModel(
            id: $id,
            name: "Model $id",
            version: '1.0',
            provider: 'Test',
            type: 'llm',
            riskLevel: $riskLevel,
            status: $status,
            registeredAt: new DateTimeImmutable(),
        );
    }
}
