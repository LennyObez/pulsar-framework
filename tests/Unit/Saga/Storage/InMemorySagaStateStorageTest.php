<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\SagaState;
use Pulsar\Saga\SagaStatus;
use Pulsar\Saga\Step\StepResult;
use Pulsar\Saga\Storage\InMemorySagaStateStorage;

#[CoversClass(InMemorySagaStateStorage::class)]
final class InMemorySagaStateStorageTest extends TestCase
{
    #[Test]
    public function saveThenFindByIdReturnsTheState(): void
    {
        $storage = new InMemorySagaStateStorage();
        $state = $this->makeState('saga-1');

        $storage->save($state);

        self::assertSame($state, $storage->findById('saga-1'));
    }

    #[Test]
    public function findByIdReturnsNullForUnknownSaga(): void
    {
        $storage = new InMemorySagaStateStorage();

        self::assertNull($storage->findById('missing'));
    }

    #[Test]
    public function saveOverwritesByeSagaId(): void
    {
        $storage = new InMemorySagaStateStorage();
        $storage->save($this->makeState('saga-1', SagaStatus::Running));
        $storage->save($this->makeState('saga-1', SagaStatus::Completed));

        self::assertSame(SagaStatus::Completed, $storage->findById('saga-1')?->status);
    }

    private function makeState(string $id, SagaStatus $status = SagaStatus::Running): SagaState
    {
        return new SagaState(
            sagaId: $id,
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 1,
            stepResults: [StepResult::success('charge', ['amount' => 100])],
            status: $status,
            context: ['orderId' => 'ORD-1'],
            startedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            completedAt: null,
        );
    }
}
