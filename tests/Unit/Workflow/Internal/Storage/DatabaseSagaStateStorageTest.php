<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Internal\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Saga\SagaState;
use Pulsar\Saga\SagaStatus;
use Pulsar\Saga\Step\StepResult;
use Pulsar\Workflow\Internal\Storage\DatabaseSagaStateStorage;

#[CoversClass(DatabaseSagaStateStorage::class)]
final class DatabaseSagaStateStorageTest extends TestCase
{
    private PdoConnection $connection;
    private DatabaseSagaStateStorage $storage;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'saga-state-test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->storage = new DatabaseSagaStateStorage($this->connection);
        $this->storage->installSchema();
    }

    #[Test]
    public function saveThenFindByIdRoundTripsAllFields(): void
    {
        $state = new SagaState(
            sagaId: 'saga-1',
            definitionId: 'order',
            definitionVersion: 3,
            currentStepIndex: 2,
            stepResults: [
                StepResult::success('charge', ['amount' => 100]),
                StepResult::success('ship'),
            ],
            status: SagaStatus::Completed,
            context: ['orderId' => 'ORD-1', 'attempts' => 2],
            startedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            completedAt: new DateTimeImmutable('2026-01-01 12:05:30'),
        );

        $this->storage->save($state);
        $found = $this->storage->findById('saga-1');

        self::assertNotNull($found);
        self::assertSame('saga-1', $found->sagaId);
        self::assertSame('order', $found->definitionId);
        self::assertSame(3, $found->definitionVersion);
        self::assertSame(2, $found->currentStepIndex);
        self::assertSame(SagaStatus::Completed, $found->status);
        self::assertSame(['orderId' => 'ORD-1', 'attempts' => 2], $found->context);
        self::assertCount(2, $found->stepResults);
        self::assertSame('charge', $found->stepResults[0]->stepName);
        self::assertSame(['amount' => 100], $found->stepResults[0]->output);
        self::assertTrue($found->stepResults[0]->success);
        self::assertSame('ship', $found->stepResults[1]->stepName);
        self::assertSame('2026-01-01 12:00:00', $found->startedAt->format('Y-m-d H:i:s'));
        self::assertNotNull($found->completedAt);
        self::assertSame('2026-01-01 12:05:30', $found->completedAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function findByIdReturnsNullForUnknownSaga(): void
    {
        self::assertNull($this->storage->findById('missing'));
    }

    #[Test]
    public function saveUpsertsBySagaId(): void
    {
        $this->storage->save($this->makeRunningState());

        $advanced = new SagaState(
            sagaId: 'saga-1',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 1,
            stepResults: [StepResult::success('charge')],
            status: SagaStatus::Completed,
            context: ['orderId' => 'ORD-1'],
            startedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            completedAt: new DateTimeImmutable('2026-01-01 00:01:00'),
        );
        $this->storage->save($advanced);

        $found = $this->storage->findById('saga-1');
        self::assertNotNull($found);
        self::assertSame(SagaStatus::Completed, $found->status);
        self::assertSame(1, $found->currentStepIndex);
        self::assertCount(1, $found->stepResults);
        self::assertNotNull($found->completedAt);
    }

    #[Test]
    public function pendingSagaRoundTripsWithNoCompletedAtAndNoResults(): void
    {
        $this->storage->save($this->makeRunningState());

        $found = $this->storage->findById('saga-1');
        self::assertNotNull($found);
        self::assertSame(SagaStatus::Running, $found->status);
        self::assertSame([], $found->stepResults);
        self::assertNull($found->completedAt);
    }

    private function makeRunningState(): SagaState
    {
        return new SagaState(
            sagaId: 'saga-1',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 0,
            stepResults: [],
            status: SagaStatus::Running,
            context: ['orderId' => 'ORD-1'],
            startedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            completedAt: null,
        );
    }
}
