<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Contracts\DeploymentGateInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringResult;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Internal\AiLifecycleManager;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryModelRegistry;

#[CoversClass(AiLifecycleManager::class)]
final class AiLifecycleManagerTest extends TestCase
{
    private InMemoryModelRegistry $registry;
    private AiAuditLoggerInterface&Stub $auditLogger;
    private AiLifecycleManager $manager;

    protected function setUp(): void
    {
        $this->registry = new InMemoryModelRegistry();
        $this->auditLogger = $this->createStub(AiAuditLoggerInterface::class);
        $this->manager = new AiLifecycleManager($this->registry, $this->auditLogger);
    }

    public function testDeployWithNoGatesSucceeds(): void
    {
        $model = $this->createModel('m1', AiModelStatus::Staging);
        $this->registry->register($model);

        $deployed = $this->manager->deploy('m1');
        self::assertSame(AiModelStatus::Production, $deployed->status);
    }

    public function testDeployWithPassingGatesSucceeds(): void
    {
        $model = $this->createModel('m1', AiModelStatus::Staging);
        $this->registry->register($model);

        $gate = $this->createStub(DeploymentGateInterface::class);
        $gate->method('name')->willReturn('test-gate');
        $gate->method('evaluate')->willReturn(true);
        $gate->method('failureReason')->willReturn('');

        $this->manager->addDeploymentGate($gate);

        $deployed = $this->manager->deploy('m1');
        self::assertSame(AiModelStatus::Production, $deployed->status);
    }

    public function testDeployWithFailingGateThrows(): void
    {
        $model = $this->createModel('m1', AiModelStatus::Staging);
        $this->registry->register($model);

        $gate = $this->createStub(DeploymentGateInterface::class);
        $gate->method('name')->willReturn('safety-check');
        $gate->method('evaluate')->willReturn(false);
        $gate->method('failureReason')->willReturn('Model safety score below threshold');

        $this->manager->addDeploymentGate($gate);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('safety-check: Model safety score below threshold');
        $this->manager->deploy('m1');
    }

    public function testDeployWithMultipleGatesCollectsAllFailures(): void
    {
        $model = $this->createModel('m1', AiModelStatus::Staging);
        $this->registry->register($model);

        $gate1 = $this->createStub(DeploymentGateInterface::class);
        $gate1->method('name')->willReturn('gate-a');
        $gate1->method('evaluate')->willReturn(false);
        $gate1->method('failureReason')->willReturn('Failure A');

        $gate2 = $this->createStub(DeploymentGateInterface::class);
        $gate2->method('name')->willReturn('gate-b');
        $gate2->method('evaluate')->willReturn(false);
        $gate2->method('failureReason')->willReturn('Failure B');

        $this->manager->addDeploymentGate($gate1);
        $this->manager->addDeploymentGate($gate2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gate-a: Failure A; gate-b: Failure B');
        $this->manager->deploy('m1');
    }

    public function testDeployThrowsForUnknownModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');
        $this->manager->deploy('nonexistent');
    }

    public function testEvaluateGatesReturnsResults(): void
    {
        $model = $this->createModel('m1');

        $passingGate = $this->createStub(DeploymentGateInterface::class);
        $passingGate->method('name')->willReturn('passing');
        $passingGate->method('evaluate')->willReturn(true);
        $passingGate->method('failureReason')->willReturn('');

        $failingGate = $this->createStub(DeploymentGateInterface::class);
        $failingGate->method('name')->willReturn('failing');
        $failingGate->method('evaluate')->willReturn(false);
        $failingGate->method('failureReason')->willReturn('Threshold not met');

        $this->manager->addDeploymentGate($passingGate);
        $this->manager->addDeploymentGate($failingGate);

        $results = $this->manager->evaluateGates($model);
        self::assertCount(2, $results);
        self::assertTrue($results[0]['passed']);
        self::assertSame('', $results[0]['reason']);
        self::assertFalse($results[1]['passed']);
        self::assertSame('Threshold not met', $results[1]['reason']);
    }

    public function testMonitorRunsAllHooks(): void
    {
        $model = $this->createModel('m1');
        $this->registry->register($model);

        $hook = $this->createStub(MonitoringHookInterface::class);
        $hook->method('name')->willReturn('drift-check');
        $hook->method('check')->willReturn(new MonitoringResult(
            healthy: true,
            hookName: 'drift-check',
            message: 'No drift detected',
            metrics: ['drift_score' => 0.02],
        ));

        $this->manager->addMonitoringHook($hook);

        $results = $this->manager->monitor('m1');
        self::assertCount(1, $results);
        self::assertTrue($results[0]->healthy);
        self::assertSame('drift-check', $results[0]->hookName);
        self::assertSame(0.02, $results[0]->metrics['drift_score']);
    }

    public function testMonitorThrowsForUnknownModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manager->monitor('nonexistent');
    }

    public function testRollbackFromProduction(): void
    {
        $model = $this->createModel('m1', AiModelStatus::Staging);
        $this->registry->register($model);

        $this->manager->deploy('m1');
        $rolledBack = $this->manager->rollback('m1');

        self::assertSame(AiModelStatus::Staging, $rolledBack->status);
    }

    public function testRollbackThrowsWhenNotInProduction(): void
    {
        $model = $this->createModel('m1', AiModelStatus::Staging);
        $this->registry->register($model);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected "production"');
        $this->manager->rollback('m1');
    }

    public function testRollbackThrowsForUnknownModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');
        $this->manager->rollback('nonexistent');
    }

    /**
     * @param non-empty-string $id
     */
    private function createModel(
        string $id = 'model-1',
        AiModelStatus $status = AiModelStatus::Development,
    ): AiModel {
        return new AiModel(
            id: $id,
            name: 'Test Model',
            version: '1.0.0',
            provider: 'test-provider',
            type: 'llm',
            riskLevel: AiModelRiskLevel::Limited,
            status: $status,
            registeredAt: new DateTimeImmutable('2026-01-01'),
        );
    }
}
