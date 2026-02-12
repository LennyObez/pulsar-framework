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
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Exception\ConcurrentTransitionException;
use Pulsar\Workflow\Internal\Storage\DatabaseWorkflowStorage;
use Pulsar\Workflow\Storage\ClassificationLevel;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;
use RuntimeException;

use function assert;
use function is_array;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Unit tests for DatabaseWorkflowStorage covering:
 * - create() — SQL parameter mapping
 * - findById() — hydration with nullable fields, returns null on missing
 * - updateState() — optimistic locking (CAS), concurrent transition detection
 * - updateStatus() — status update with/without completion timestamp
 * - findByDefinition() — query and multi-row hydration
 * - findByStatus() — query with enum value
 * - updateTimeout() — nullable timeout update
 * - findExpiredTimeouts() — limit and status filter
 * - hydrateInstance() — all field types including nullable dates
 */
#[CoversClass(DatabaseWorkflowStorage::class)]
final class DatabaseWorkflowStorageTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);
    }

    private function storage(?EncryptorInterface $encryptor = null): DatabaseWorkflowStorage
    {
        return new DatabaseWorkflowStorage($this->connection, $encryptor);
    }

    private function makeInstance(
        string $id = 'inst-1',
        string $state = 'draft',
        int $version = 1,
        WorkflowInstanceStatus $status = WorkflowInstanceStatus::Active,
    ): WorkflowInstance {
        return new WorkflowInstance(
            id: $id,
            definitionId: 'order',
            definitionVersion: 1,
            currentState: $state,
            context: new ClassifiedContext(),
            version: $version,
            status: $status,
            startedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            completedAt: null,
            startedBy: 'user-1',
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeRow(array $overrides = []): Row
    {
        /** @var array<string, mixed> $defaults */
        $defaults = [
            'id' => 'inst-1',
            'definition_id' => 'order',
            'definition_version' => 1,
            'current_state' => 'draft',
            'context' => json_encode(['values' => [], 'classifications' => [], 'encrypted' => []], JSON_THROW_ON_ERROR),
            'version' => 1,
            'status' => 'active',
            'started_at' => '2026-01-01 00:00:00',
            'completed_at' => null,
            'started_by' => 'user-1',
            'timeout_at' => null,
        ];

        return new Row([...$defaults, ...$overrides]);
    }

    // =========================================================================
    // create()
    // =========================================================================

    #[Test]
    public function create_executes_insert_with_correct_parameters(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO workflow_instances'),
                self::callback(static function (array $params): bool {
                    return $params['id'] === 'inst-1'
                        && $params['definition_id'] === 'order'
                        && $params['definition_version'] === 1
                        && $params['current_state'] === 'draft'
                        && $params['version'] === 1
                        && $params['status'] === 'active'
                        && $params['started_by'] === 'user-1'
                        && $params['completed_at'] === null;
                }),
            );

        $storage = new DatabaseWorkflowStorage($connection);
        $storage->create($this->makeInstance());
    }

    #[Test]
    public function create_serializes_context_as_json(): void
    {
        $ctx = new ClassifiedContext()
            ->set('amount', 100, ClassificationLevel::Public);

        $instance = new WorkflowInstance(
            id: 'inst-2',
            definitionId: 'order',
            definitionVersion: 1,
            currentState: 'draft',
            context: $ctx,
            version: 1,
            status: WorkflowInstanceStatus::Active,
            startedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            completedAt: null,
            startedBy: 'user-1',
        );

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static function (array $params): bool {
                    assert(is_string($params['context']));
                    $contextData = json_decode($params['context'], true, 512, JSON_THROW_ON_ERROR);
                    assert(is_array($contextData));
                    $values = $contextData['values'];
                    assert(is_array($values));
                    $classifications = $contextData['classifications'];
                    assert(is_array($classifications));

                    return isset($values['amount'])
                        && $values['amount'] === 100
                        && $classifications['amount'] === 'public';
                }),
            );

        $storage = new DatabaseWorkflowStorage($connection);
        $storage->create($instance);
    }

    #[Test]
    public function create_formats_completed_at_when_present(): void
    {
        $instance = new WorkflowInstance(
            id: 'inst-1',
            definitionId: 'order',
            definitionVersion: 1,
            currentState: 'done',
            context: new ClassifiedContext(),
            version: 3,
            status: WorkflowInstanceStatus::Completed,
            startedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            completedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            startedBy: 'user-1',
        );

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn(array $params): bool => $params['completed_at'] === '2026-01-01 12:00:00'),
            );

        $storage = new DatabaseWorkflowStorage($connection);
        $storage->create($instance);
    }

    // =========================================================================
    // findById()
    // =========================================================================

    #[Test]
    public function findById_returns_hydrated_instance(): void
    {
        $this->connection->method('query')->willReturn(new Result([$this->makeRow()]));

        $instance = $this->storage()->findById('inst-1');

        self::assertNotNull($instance);
        self::assertSame('inst-1', $instance->id);
        self::assertSame('order', $instance->definitionId);
        self::assertSame(1, $instance->definitionVersion);
        self::assertSame('draft', $instance->currentState);
        self::assertSame(1, $instance->version);
        self::assertSame(WorkflowInstanceStatus::Active, $instance->status);
        self::assertSame('user-1', $instance->startedBy);
        self::assertNull($instance->completedAt);
        self::assertNull($instance->timeoutAt);
    }

    #[Test]
    public function findById_returns_null_when_not_found(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        self::assertNull($this->storage()->findById('nonexistent'));
    }

    #[Test]
    public function findById_hydrates_completed_at_when_present(): void
    {
        $row = $this->makeRow(['completed_at' => '2026-06-15 10:30:00']);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $instance = $this->storage()->findById('inst-1');

        self::assertNotNull($instance);
        self::assertNotNull($instance->completedAt);
        self::assertSame('2026-06-15 10:30:00', $instance->completedAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function findById_hydrates_timeout_at_when_present(): void
    {
        $row = $this->makeRow(['timeout_at' => '2026-07-01 08:00:00']);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $instance = $this->storage()->findById('inst-1');

        self::assertNotNull($instance);
        self::assertNotNull($instance->timeoutAt);
        self::assertSame('2026-07-01 08:00:00', $instance->timeoutAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function findById_hydrates_context_from_serialized_json(): void
    {
        $contextJson = json_encode([
            'values' => ['amount' => 500],
            'classifications' => ['amount' => 'internal'],
            'encrypted' => [],
        ], JSON_THROW_ON_ERROR);
        $row = $this->makeRow(['context' => $contextJson]);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $instance = $this->storage()->findById('inst-1');

        self::assertNotNull($instance);
        self::assertTrue($instance->context->has('amount'));
        self::assertSame(500, $instance->context->get('amount'));
    }

    #[Test]
    public function findById_uses_encryptor_for_decryption(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('"secret_value"');

        $contextJson = json_encode([
            'values' => ['ssn' => 'encrypted_data'],
            'classifications' => ['ssn' => 'pii'],
            'encrypted' => ['ssn'],
        ], JSON_THROW_ON_ERROR);
        $row = $this->makeRow(['context' => $contextJson]);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $instance = new DatabaseWorkflowStorage($this->connection, $encryptor)->findById('inst-1');

        self::assertNotNull($instance);
        self::assertSame('secret_value', $instance->context->get('ssn'));
    }

    // =========================================================================
    // updateState() — optimistic locking
    // =========================================================================

    #[Test]
    public function updateState_succeeds_when_version_matches(): void
    {
        // findById returns a valid instance first
        $row = $this->makeRow(['current_state' => 'draft', 'version' => 1]);
        $this->connection->method('query')->willReturn(new Result([$row]));
        $this->connection->method('execute')->willReturn(1); // 1 row affected

        $result = $this->storage()->updateState(
            'inst-1',
            'review',
            1,
            new ActorContext(subjectId: 'user-1'),
        );

        self::assertSame('review', $result->currentState);
        self::assertSame(2, $result->version);
    }

    #[Test]
    public function updateState_throws_ConcurrentTransitionException_on_version_mismatch(): void
    {
        $row = $this->makeRow(['current_state' => 'draft', 'version' => 1]);
        $this->connection->method('query')->willReturn(new Result([$row]));
        $this->connection->method('execute')->willReturn(0); // 0 rows affected = version mismatch

        $this->expectException(ConcurrentTransitionException::class);
        $this->expectExceptionMessage('inst-1');

        $this->storage()->updateState(
            'inst-1',
            'review',
            1,
            new ActorContext(subjectId: 'user-1'),
        );
    }

    #[Test]
    public function updateState_throws_RuntimeException_when_instance_not_found(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->storage()->updateState(
            'missing',
            'review',
            1,
            new ActorContext(subjectId: 'user-1'),
        );
    }

    #[Test]
    public function updateState_passes_reason_argument(): void
    {
        $row = $this->makeRow();
        $this->connection->method('query')->willReturn(new Result([$row]));
        $this->connection->method('execute')->willReturn(1);

        $result = $this->storage()->updateState(
            'inst-1',
            'review',
            1,
            new ActorContext(subjectId: 'user-1'),
            'Expedited review',
        );

        self::assertSame('review', $result->currentState);
    }

    // =========================================================================
    // updateStatus()
    // =========================================================================

    #[Test]
    public function updateStatus_to_completed_sets_completed_at(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('completed_at'),
                self::callback(static fn(array $params): bool => $params['status'] === 'completed'
                    && $params['completed_at'] !== null
                    && $params['id'] === 'inst-1'),
            );

        $storage = new DatabaseWorkflowStorage($connection);
        $storage->updateStatus('inst-1', WorkflowInstanceStatus::Completed);
    }

    #[Test]
    public function updateStatus_to_active_does_not_set_completed_at(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::logicalNot(self::stringContains('completed_at')),
                self::callback(static fn(array $params): bool => $params['status'] === 'active'
                    && $params['id'] === 'inst-1'),
            );

        $storage = new DatabaseWorkflowStorage($connection);
        $storage->updateStatus('inst-1', WorkflowInstanceStatus::Active);
    }

    #[Test]
    public function updateStatus_to_failed_does_not_set_completed_at(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::logicalNot(self::stringContains('completed_at')),
                self::callback(static fn(array $params): bool => $params['status'] === 'failed'),
            );

        $storage = new DatabaseWorkflowStorage($connection);
        $storage->updateStatus('inst-1', WorkflowInstanceStatus::Failed);
    }

    // =========================================================================
    // findByDefinition()
    // =========================================================================

    #[Test]
    public function findByDefinition_returns_all_matching_instances(): void
    {
        $rows = [
            $this->makeRow(['id' => 'inst-1', 'current_state' => 'draft']),
            $this->makeRow(['id' => 'inst-2', 'current_state' => 'review']),
        ];
        $this->connection->method('query')->willReturn(new Result($rows));

        $instances = $this->storage()->findByDefinition('order');

        self::assertCount(2, $instances);
        self::assertSame('inst-1', $instances[0]->id);
        self::assertSame('inst-2', $instances[1]->id);
    }

    #[Test]
    public function findByDefinition_returns_empty_when_none_found(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $instances = $this->storage()->findByDefinition('unknown');

        self::assertSame([], $instances);
    }

    // =========================================================================
    // findByStatus()
    // =========================================================================

    #[Test]
    public function findByStatus_returns_instances_with_matching_status(): void
    {
        $rows = [$this->makeRow(['status' => 'active'])];
        $this->connection->method('query')->willReturn(new Result($rows));

        $instances = $this->storage()->findByStatus(WorkflowInstanceStatus::Active);

        self::assertCount(1, $instances);
        self::assertSame(WorkflowInstanceStatus::Active, $instances[0]->status);
    }

    #[Test]
    public function findByStatus_returns_empty_when_no_matches(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $instances = $this->storage()->findByStatus(WorkflowInstanceStatus::Completed);

        self::assertSame([], $instances);
    }

    // =========================================================================
    // updateTimeout()
    // =========================================================================

    #[Test]
    public function updateTimeout_with_datetime_sets_timeout(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('timeout_at'),
                self::callback(static fn(array $p): bool => $p['timeout_at'] === '2026-06-15 12:00:00'
                    && $p['id'] === 'inst-1'),
            );

        $storage = new DatabaseWorkflowStorage($connection);
        $storage->updateTimeout('inst-1', new DateTimeImmutable('2026-06-15 12:00:00'));
    }

    #[Test]
    public function updateTimeout_with_null_clears_timeout(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn(array $p): bool => $p['timeout_at'] === null),
            );

        $storage = new DatabaseWorkflowStorage($connection);
        $storage->updateTimeout('inst-1', null);
    }

    // =========================================================================
    // findExpiredTimeouts()
    // =========================================================================

    #[Test]
    public function findExpiredTimeouts_returns_active_instances_past_deadline(): void
    {
        $rows = [
            $this->makeRow([
                'id' => 'inst-expired',
                'status' => 'active',
                'timeout_at' => '2026-01-01 00:00:00',
            ]),
        ];
        $this->connection->method('query')->willReturn(new Result($rows));

        $instances = $this->storage()->findExpiredTimeouts(50);

        self::assertCount(1, $instances);
        self::assertSame('inst-expired', $instances[0]->id);
    }

    #[Test]
    public function findExpiredTimeouts_returns_empty_when_none_expired(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $instances = $this->storage()->findExpiredTimeouts();

        self::assertSame([], $instances);
    }

    // =========================================================================
    // Hydration edge cases
    // =========================================================================

    #[Test]
    public function hydration_handles_all_status_enum_values(): void
    {
        foreach (WorkflowInstanceStatus::cases() as $status) {
            $row = $this->makeRow(['status' => $status->value]);
            $conn = $this->createStub(ConnectionInterface::class);
            $conn->method('driver')->willReturn(Driver::MySQL);
            $conn->method('query')->willReturn(new Result([$row]));

            $instance = new DatabaseWorkflowStorage($conn)->findById('inst-1');

            self::assertNotNull($instance);
            self::assertSame($status, $instance->status, "Failed for status: {$status->value}");
        }
    }

    #[Test]
    public function hydration_parses_started_at_as_datetime(): void
    {
        $row = $this->makeRow(['started_at' => '2026-03-08 15:30:45']);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $instance = $this->storage()->findById('inst-1');

        self::assertNotNull($instance);
        self::assertSame('2026-03-08', $instance->startedAt->format('Y-m-d'));
        self::assertSame('15:30:45', $instance->startedAt->format('H:i:s'));
    }
}
