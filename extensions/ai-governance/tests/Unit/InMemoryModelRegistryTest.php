<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testRegisterAndRetrieveModel(): void
    {
        $model = $this->createModel('model-1');
        $this->registry->register($model);

        $retrieved = $this->registry->get('model-1');
        self::assertNotNull($retrieved);
        self::assertSame('model-1', $retrieved->id);
        self::assertSame('Test Model', $retrieved->name);
    }

    public function testGetReturnsNullForUnknownModel(): void
    {
        self::assertNull($this->registry->get('nonexistent'));
    }

    public function testAllReturnsAllRegisteredModels(): void
    {
        $this->registry->register($this->createModel('m1'));
        $this->registry->register($this->createModel('m2'));

        $all = $this->registry->all();
        self::assertCount(2, $all);
        self::assertArrayHasKey('m1', $all);
        self::assertArrayHasKey('m2', $all);
    }

    public function testByStatusFiltersCorrectly(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Development));
        $this->registry->register($this->createModel('m2', status: AiModelStatus::Testing));
        $this->registry->register($this->createModel('m3', status: AiModelStatus::Development));

        $devModels = $this->registry->byStatus(AiModelStatus::Development);
        self::assertCount(2, $devModels);
        self::assertSame('m1', $devModels[0]->id);
        self::assertSame('m3', $devModels[1]->id);
    }

    public function testByRiskLevelFiltersCorrectly(): void
    {
        $this->registry->register($this->createModel('m1', riskLevel: AiModelRiskLevel::High));
        $this->registry->register($this->createModel('m2', riskLevel: AiModelRiskLevel::Minimal));
        $this->registry->register($this->createModel('m3', riskLevel: AiModelRiskLevel::High));

        $highRisk = $this->registry->byRiskLevel(AiModelRiskLevel::High);
        self::assertCount(2, $highRisk);
    }

    #[DataProvider('validTransitionsProvider')]
    public function testValidStatusTransitions(AiModelStatus $from, AiModelStatus $to): void
    {
        $this->registry->register($this->createModel('m1', status: $from));
        $updated = $this->registry->transitionStatus('m1', $to);

        self::assertSame($to, $updated->status);
    }

    /**
     * @return iterable<string, array{AiModelStatus, AiModelStatus}>
     */
    public static function validTransitionsProvider(): iterable
    {
        yield 'development → testing' => [AiModelStatus::Development, AiModelStatus::Testing];
        yield 'testing → staging' => [AiModelStatus::Testing, AiModelStatus::Staging];
        yield 'staging → production' => [AiModelStatus::Staging, AiModelStatus::Production];
        yield 'production → deprecated' => [AiModelStatus::Production, AiModelStatus::Deprecated];
        yield 'deprecated → retired' => [AiModelStatus::Deprecated, AiModelStatus::Retired];
        yield 'testing → development' => [AiModelStatus::Testing, AiModelStatus::Development];
        yield 'staging → testing' => [AiModelStatus::Staging, AiModelStatus::Testing];
        yield 'deprecated → production' => [AiModelStatus::Deprecated, AiModelStatus::Production];
    }

    #[DataProvider('invalidTransitionsProvider')]
    public function testInvalidStatusTransitionsThrow(AiModelStatus $from, AiModelStatus $to): void
    {
        $this->registry->register($this->createModel('m1', status: $from));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status transition');
        $this->registry->transitionStatus('m1', $to);
    }

    /**
     * @return iterable<string, array{AiModelStatus, AiModelStatus}>
     */
    public static function invalidTransitionsProvider(): iterable
    {
        yield 'development → production' => [AiModelStatus::Development, AiModelStatus::Production];
        yield 'retired → development' => [AiModelStatus::Retired, AiModelStatus::Development];
        yield 'production → development' => [AiModelStatus::Production, AiModelStatus::Development];
    }

    public function testTransitionStatusThrowsForUnknownModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');
        $this->registry->transitionStatus('nonexistent', AiModelStatus::Testing);
    }

    public function testUpdateRiskLevel(): void
    {
        $this->registry->register($this->createModel('m1', riskLevel: AiModelRiskLevel::Minimal));
        $updated = $this->registry->updateRiskLevel('m1', AiModelRiskLevel::High);

        self::assertSame(AiModelRiskLevel::High, $updated->riskLevel);

        // Verify persisted
        $retrieved = $this->registry->get('m1');
        self::assertNotNull($retrieved);
        self::assertSame(AiModelRiskLevel::High, $retrieved->riskLevel);
    }

    public function testUpdateRiskLevelThrowsForUnknownModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->updateRiskLevel('nonexistent', AiModelRiskLevel::High);
    }

    public function testPreviousStatusTrackedAfterTransition(): void
    {
        $this->registry->register($this->createModel('m1', status: AiModelStatus::Development));
        $this->registry->transitionStatus('m1', AiModelStatus::Testing);

        self::assertSame(AiModelStatus::Development, $this->registry->getPreviousStatus('m1'));
    }

    public function testRegisterOverwritesExisting(): void
    {
        $this->registry->register($this->createModel('m1', riskLevel: AiModelRiskLevel::Minimal));
        $this->registry->register($this->createModel('m1', riskLevel: AiModelRiskLevel::High));

        $model = $this->registry->get('m1');
        self::assertNotNull($model);
        self::assertSame(AiModelRiskLevel::High, $model->riskLevel);
    }

    /**
     * @param non-empty-string $id
     */
    private function createModel(
        string $id = 'model-1',
        AiModelStatus $status = AiModelStatus::Development,
        AiModelRiskLevel $riskLevel = AiModelRiskLevel::Limited,
    ): AiModel {
        return new AiModel(
            id: $id,
            name: 'Test Model',
            version: '1.0.0',
            provider: 'test-provider',
            type: 'llm',
            riskLevel: $riskLevel,
            status: $status,
            registeredAt: new DateTimeImmutable('2026-01-01'),
        );
    }
}
