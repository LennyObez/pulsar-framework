<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Internal\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Workflow\Internal\Storage\DatabaseSagaStepResultStorage;
use Pulsar\Workflow\Storage\SagaStepDirection;
use Pulsar\Workflow\Storage\SagaStepResult;
use Pulsar\Workflow\Storage\SagaStepStatus;

use function assert;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Unit tests for DatabaseSagaStepResultStorage covering:
 * - record() — insert with all fields including nullable JSON
 * - updateStatus() — status and error message update
 * - markCompleted() — status, result data, and completed_at
 * - getByInstance() — ordered query and hydration
 * - getByDirection() — filtered query
 * - findByIdempotencyKey() — nullable result
 * - incrementAttempts() — counter increment
 * - Hydration of all field types including nullable dates and JSON
 */
#[CoversClass(DatabaseSagaStepResultStorage::class)]
final class DatabaseSagaStepResultStorageTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);
    }

    private function storage(): DatabaseSagaStepResultStorage
    {
        return new DatabaseSagaStepResultStorage($this->connection);
    }

    /**
     * @param array<string, mixed>|null $resultData
     */
    private function makeStepResult(
        string $id = 'step-1',
        string $instanceId = 'saga-1',
        string $stepName = 'charge',
        int $stepIndex = 0,
        SagaStepDirection $direction = SagaStepDirection::Forward,
        SagaStepStatus $status = SagaStepStatus::Running,
        ?string $idempotencyKey = null,
        int $attempts = 1,
        ?array $resultData = null,
        ?string $errorMessage = null,
        ?DateTimeImmutable $completedAt = null,
    ): SagaStepResult {
        return new SagaStepResult(
            id: $id,
            instanceId: $instanceId,
            stepName: $stepName,
            stepIndex: $stepIndex,
            direction: $direction,
            status: $status,
            idempotencyKey: $idempotencyKey,
            attempts: $attempts,
            resultData: $resultData,
            errorMessage: $errorMessage,
            startedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            completedAt: $completedAt,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeRow(array $overrides = []): Row
    {
        /** @var array<string, mixed> $defaults */
        $defaults = [
            'id' => 'step-1',
            'instance_id' => 'saga-1',
            'step_name' => 'charge',
            'step_index' => 0,
            'direction' => 'forward',
            'status' => 'running',
            'idempotency_key' => null,
            'attempts' => 1,
            'result_data' => null,
            'error_message' => null,
            'started_at' => '2026-01-01 00:00:00',
            'completed_at' => null,
        ];

        return new Row([...$defaults, ...$overrides]);
    }

    // =========================================================================
    // record()
    // =========================================================================

    #[Test]
    public function record_executes_insert_with_correct_parameters(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO saga_step_results'),
                self::callback(static function (array $p): bool {
                    return $p['id'] === 'step-1'
                        && $p['instance_id'] === 'saga-1'
                        && $p['step_name'] === 'charge'
                        && $p['step_index'] === 0
                        && $p['direction'] === 'forward'
                        && $p['status'] === 'running'
                        && $p['idempotency_key'] === null
                        && $p['attempts'] === 1
                        && $p['result_data'] === null
                        && $p['error_message'] === null
                        && $p['completed_at'] === null;
                }),
            );

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->record($this->makeStepResult());
    }

    #[Test]
    public function record_serializes_result_data_as_json(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static function (array $p): bool {
                    assert(is_string($p['result_data']));
                    $data = json_decode($p['result_data'], true, 512, JSON_THROW_ON_ERROR);

                    return $data === ['transactionId' => 'txn_abc'];
                }),
            );

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->record($this->makeStepResult(resultData: ['transactionId' => 'txn_abc']));
    }

    #[Test]
    public function record_formats_completed_at_when_present(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn(array $p): bool => $p['completed_at'] === '2026-06-15 12:00:00'),
            );

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->record($this->makeStepResult(completedAt: new DateTimeImmutable('2026-06-15 12:00:00')));
    }

    #[Test]
    public function record_maps_direction_enum_to_string(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn(array $p): bool => $p['direction'] === 'compensating'),
            );

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->record($this->makeStepResult(direction: SagaStepDirection::Compensating));
    }

    #[Test]
    public function record_maps_status_enum_to_string(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn(array $p): bool => $p['status'] === 'completed'),
            );

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->record($this->makeStepResult(status: SagaStepStatus::Completed));
    }

    #[Test]
    public function record_passes_idempotency_key(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn(array $p): bool => $p['idempotency_key'] === 'charge-ORD-42'),
            );

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->record($this->makeStepResult(idempotencyKey: 'charge-ORD-42'));
    }

    // =========================================================================
    // updateStatus()
    // =========================================================================

    #[Test]
    public function updateStatus_updates_status_and_error_message(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('UPDATE saga_step_results'),
                self::callback(static fn(array $p): bool => $p['status'] === 'failed'
                    && $p['error_message'] === 'Payment declined'
                    && $p['id'] === 'step-1'),
            );

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->updateStatus('step-1', SagaStepStatus::Failed, 'Payment declined');
    }

    #[Test]
    public function updateStatus_with_null_error_message(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn(array $p): bool => $p['error_message'] === null),
            );

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->updateStatus('step-1', SagaStepStatus::Running);
    }

    // =========================================================================
    // markCompleted()
    // =========================================================================

    #[Test]
    public function markCompleted_sets_status_and_completed_at(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('UPDATE saga_step_results'),
                self::callback(static fn(array $p): bool => $p['status'] === 'completed'
                    && $p['completed_at'] !== null
                    && $p['id'] === 'step-1'
                    && $p['result_data'] === null),
            );

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->markCompleted('step-1');
    }

    #[Test]
    public function markCompleted_serializes_result_data(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static function (array $p): bool {
                    assert(is_string($p['result_data']));
                    $data = json_decode($p['result_data'], true, 512, JSON_THROW_ON_ERROR);

                    return $data === ['txn' => 'T123'];
                }),
            );

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->markCompleted('step-1', ['txn' => 'T123']);
    }

    // =========================================================================
    // getByInstance()
    // =========================================================================

    #[Test]
    public function getByInstance_returns_hydrated_results_ordered_by_index(): void
    {
        $rows = [
            $this->makeRow(['id' => 'step-1', 'step_index' => 0, 'step_name' => 'charge']),
            $this->makeRow(['id' => 'step-2', 'step_index' => 1, 'step_name' => 'confirm']),
        ];
        $this->connection->method('query')->willReturn(new Result($rows));

        $results = $this->storage()->getByInstance('saga-1');

        self::assertCount(2, $results);
        self::assertSame('charge', $results[0]->stepName);
        self::assertSame(0, $results[0]->stepIndex);
        self::assertSame('confirm', $results[1]->stepName);
        self::assertSame(1, $results[1]->stepIndex);
    }

    #[Test]
    public function getByInstance_returns_empty_array_when_none_found(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        self::assertSame([], $this->storage()->getByInstance('missing'));
    }

    // =========================================================================
    // getByDirection()
    // =========================================================================

    #[Test]
    public function getByDirection_filters_by_direction(): void
    {
        $rows = [$this->makeRow(['direction' => 'compensating'])];
        $this->connection->method('query')->willReturn(new Result($rows));

        $results = $this->storage()->getByDirection('saga-1', SagaStepDirection::Compensating);

        self::assertCount(1, $results);
        self::assertSame(SagaStepDirection::Compensating, $results[0]->direction);
    }

    #[Test]
    public function getByDirection_returns_empty_when_no_match(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        self::assertSame([], $this->storage()->getByDirection('saga-1', SagaStepDirection::Forward));
    }

    // =========================================================================
    // findByIdempotencyKey()
    // =========================================================================

    #[Test]
    public function findByIdempotencyKey_returns_result_when_found(): void
    {
        $row = $this->makeRow(['idempotency_key' => 'charge-ORD-42']);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $result = $this->storage()->findByIdempotencyKey('charge-ORD-42');

        self::assertNotNull($result);
        self::assertSame('charge-ORD-42', $result->idempotencyKey);
    }

    #[Test]
    public function findByIdempotencyKey_returns_null_when_not_found(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        self::assertNull($this->storage()->findByIdempotencyKey('nonexistent'));
    }

    // =========================================================================
    // incrementAttempts()
    // =========================================================================

    #[Test]
    public function incrementAttempts_executes_update(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('attempts = attempts + 1'),
                self::callback(static fn(array $p): bool => $p['id'] === 'step-1'),
            );

        $storage = new DatabaseSagaStepResultStorage($connection);
        $storage->incrementAttempts('step-1');
    }

    // =========================================================================
    // Hydration edge cases
    // =========================================================================

    #[Test]
    public function hydration_parses_result_data_json(): void
    {
        $resultJson = json_encode(['amount' => 500, 'currency' => 'USD'], JSON_THROW_ON_ERROR);
        $row = $this->makeRow(['result_data' => $resultJson, 'status' => 'completed']);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $results = $this->storage()->getByInstance('saga-1');

        self::assertCount(1, $results);
        self::assertSame(['amount' => 500, 'currency' => 'USD'], $results[0]->resultData);
    }

    #[Test]
    public function hydration_handles_null_result_data(): void
    {
        $row = $this->makeRow(['result_data' => null]);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $results = $this->storage()->getByInstance('saga-1');

        self::assertCount(1, $results);
        self::assertNull($results[0]->resultData);
    }

    #[Test]
    public function hydration_handles_null_completed_at(): void
    {
        $row = $this->makeRow(['completed_at' => null]);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $results = $this->storage()->getByInstance('saga-1');

        self::assertCount(1, $results);
        self::assertNull($results[0]->completedAt);
    }

    #[Test]
    public function hydration_parses_completed_at_datetime(): void
    {
        $row = $this->makeRow(['completed_at' => '2026-06-15 14:30:00']);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $results = $this->storage()->getByInstance('saga-1');

        self::assertNotNull($results[0]->completedAt);
        self::assertSame('2026-06-15 14:30:00', $results[0]->completedAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function hydration_maps_all_direction_enum_values(): void
    {
        foreach (SagaStepDirection::cases() as $direction) {
            $row = $this->makeRow(['direction' => $direction->value]);
            $conn = $this->createStub(ConnectionInterface::class);
            $conn->method('driver')->willReturn(Driver::MySQL);
            $conn->method('query')->willReturn(new Result([$row]));

            $results = new DatabaseSagaStepResultStorage($conn)->getByInstance('saga-1');

            self::assertSame($direction, $results[0]->direction, "Failed for direction: {$direction->value}");
        }
    }

    #[Test]
    public function hydration_maps_all_status_enum_values(): void
    {
        foreach (SagaStepStatus::cases() as $status) {
            $row = $this->makeRow(['status' => $status->value]);
            $conn = $this->createStub(ConnectionInterface::class);
            $conn->method('driver')->willReturn(Driver::MySQL);
            $conn->method('query')->willReturn(new Result([$row]));

            $results = new DatabaseSagaStepResultStorage($conn)->getByInstance('saga-1');

            self::assertSame($status, $results[0]->status, "Failed for status: {$status->value}");
        }
    }

    #[Test]
    public function hydration_handles_null_error_message(): void
    {
        $row = $this->makeRow(['error_message' => null]);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $results = $this->storage()->getByInstance('saga-1');

        self::assertNull($results[0]->errorMessage);
    }

    #[Test]
    public function hydration_handles_error_message_present(): void
    {
        $row = $this->makeRow(['error_message' => 'Payment declined', 'status' => 'failed']);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $results = $this->storage()->getByInstance('saga-1');

        self::assertSame('Payment declined', $results[0]->errorMessage);
    }

    #[Test]
    public function hydration_handles_null_idempotency_key(): void
    {
        $row = $this->makeRow(['idempotency_key' => null]);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $results = $this->storage()->getByInstance('saga-1');

        self::assertNull($results[0]->idempotencyKey);
    }

    #[Test]
    public function hydration_preserves_started_at_datetime(): void
    {
        $row = $this->makeRow(['started_at' => '2026-03-08 09:15:30']);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $results = $this->storage()->getByInstance('saga-1');

        self::assertSame('2026-03-08 09:15:30', $results[0]->startedAt->format('Y-m-d H:i:s'));
    }
}
