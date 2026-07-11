<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Internal\Storage;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Exception\ConcurrentTransitionException;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;
use Pulsar\Workflow\Storage\WorkflowStorageInterface;
use RuntimeException;

use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * PDO-based workflow storage using the Database module's ConnectionInterface.
 *
 * Implements optimistic locking via compare-and-swap on the version column.
 * All context data is serialized as JSON with classification metadata.
 */
#[Internal(reason: 'Use WorkflowStorageInterface port')]
final readonly class DatabaseWorkflowStorage implements WorkflowStorageInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private ?EncryptorInterface $encryptor = null,
    ) {}

    #[Override]
    public function create(WorkflowInstance $instance): void
    {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO workflow_instances (
                    id, definition_id, definition_version, current_state,
                    context, version, status, started_at, completed_at, started_by, timeout_at
                ) VALUES (
                    :id, :definition_id, :definition_version, :current_state,
                    :context, :version, :status, :started_at, :completed_at, :started_by, :timeout_at
                )
                SQL,
            [
                'id' => $instance->id,
                'definition_id' => $instance->definitionId,
                'definition_version' => $instance->definitionVersion,
                'current_state' => $instance->currentState,
                'context' => json_encode($instance->context->serialize($this->encryptor), JSON_THROW_ON_ERROR),
                'version' => $instance->version,
                'status' => $instance->status->value,
                'started_at' => $instance->startedAt->format('Y-m-d H:i:s'),
                'completed_at' => $instance->completedAt?->format('Y-m-d H:i:s'),
                'started_by' => $instance->startedBy,
                'timeout_at' => $instance->timeoutAt?->format('Y-m-d H:i:s'),
            ],
        );
    }

    #[Override]
    public function findById(string $id): ?WorkflowInstance
    {
        $result = $this->connection->query(
            'SELECT * FROM workflow_instances WHERE id = :id',
            ['id' => $id],
        );

        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateInstance($row);
    }

    #[Override]
    public function updateState(
        string $id,
        string $newState,
        int $expectedVersion,
        ActorContext $actor,
        ?string $reason = null,
    ): WorkflowInstance {
        // Read current instance BEFORE the CAS update to capture context data.
        // This avoids the race condition of reading AFTER the update, where
        // a concurrent process could modify the instance between our UPDATE
        // and our SELECT.
        $current = $this->findById($id);

        if ($current === null) {
            throw new RuntimeException(sprintf(
                'Workflow instance "%s" not found',
                $id,
            ));
        }

        $affectedRows = $this->connection->execute(
            <<<'SQL'
                UPDATE workflow_instances
                SET current_state = :new_state,
                    version = version + 1
                WHERE id = :id AND version = :expected_version
                SQL,
            [
                'new_state' => $newState,
                'id' => $id,
                'expected_version' => $expectedVersion,
            ],
        );

        if ($affectedRows === 0) {
            throw ConcurrentTransitionException::forInstance($id, $expectedVersion);
        }

        // Construct the updated instance from pre-read data + known mutations.
        // This is safe: CAS success guarantees no concurrent modification
        // occurred between our read and our update.
        return $current->withState($newState, $expectedVersion + 1);
    }

    #[Override]
    public function updateStatus(string $id, WorkflowInstanceStatus $status): void
    {
        $completedAt = ($status === WorkflowInstanceStatus::Completed)
            ? new DateTimeImmutable()->format('Y-m-d H:i:s')
            : null;

        if ($completedAt !== null) {
            $this->connection->execute(
                <<<'SQL'
                    UPDATE workflow_instances
                    SET status = :status, completed_at = :completed_at
                    WHERE id = :id
                    SQL,
                [
                    'status' => $status->value,
                    'completed_at' => $completedAt,
                    'id' => $id,
                ],
            );
        } else {
            $this->connection->execute(
                'UPDATE workflow_instances SET status = :status WHERE id = :id',
                [
                    'status' => $status->value,
                    'id' => $id,
                ],
            );
        }
    }

    #[Override]
    public function findByDefinition(string $definitionId): array
    {
        $result = $this->connection->query(
            'SELECT * FROM workflow_instances WHERE definition_id = :definition_id ORDER BY started_at DESC',
            ['definition_id' => $definitionId],
        );

        return $result->map(fn(Row $row): WorkflowInstance => $this->hydrateInstance($row));
    }

    #[Override]
    public function findByStatus(WorkflowInstanceStatus $status): array
    {
        $result = $this->connection->query(
            'SELECT * FROM workflow_instances WHERE status = :status ORDER BY started_at DESC',
            ['status' => $status->value],
        );

        return $result->map(fn(Row $row): WorkflowInstance => $this->hydrateInstance($row));
    }

    #[Override]
    public function updateTimeout(string $id, ?DateTimeImmutable $timeoutAt): void
    {
        $this->connection->execute(
            'UPDATE workflow_instances SET timeout_at = :timeout_at WHERE id = :id',
            [
                'timeout_at' => $timeoutAt?->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );
    }

    #[Override]
    public function findExpiredTimeouts(int $limit = 100): array
    {
        $now = new DateTimeImmutable()->format('Y-m-d H:i:s');

        $result = $this->connection->query(
            <<<'SQL'
                SELECT * FROM workflow_instances
                WHERE status = :status
                  AND timeout_at IS NOT NULL
                  AND timeout_at <= :now
                ORDER BY timeout_at ASC
                LIMIT :limit
                SQL,
            [
                'status' => WorkflowInstanceStatus::Active->value,
                'now' => $now,
                'limit' => $limit,
            ],
        );

        return $result->map(fn(Row $row): WorkflowInstance => $this->hydrateInstance($row));
    }

    private function hydrateInstance(Row $row): WorkflowInstance
    {
        /** @var array{values?: array<string, mixed>, classifications?: array<string, string>, encrypted?: list<string>} $contextData */
        $contextData = json_decode($row->getString('context'), true, 512, JSON_THROW_ON_ERROR);

        $completedAtRaw = $row->getNullableString('completed_at');
        $completedAt = $completedAtRaw !== null
            ? new DateTimeImmutable($completedAtRaw)
            : null;

        $timeoutAtRaw = $row->getNullableString('timeout_at');
        $timeoutAt = $timeoutAtRaw !== null
            ? new DateTimeImmutable($timeoutAtRaw)
            : null;

        return new WorkflowInstance(
            id: $row->getString('id'),
            definitionId: $row->getString('definition_id'),
            definitionVersion: $row->getInt('definition_version'),
            currentState: $row->getString('current_state'),
            context: ClassifiedContext::fromSerialized($contextData, $this->encryptor),
            version: $row->getInt('version'),
            status: WorkflowInstanceStatus::from($row->getString('status')),
            startedAt: new DateTimeImmutable($row->getString('started_at')),
            completedAt: $completedAt,
            startedBy: $row->getString('started_by'),
            timeoutAt: $timeoutAt,
        );
    }

    /**
     * Idempotent DDL: create the `workflow_instances` table. Safe to re-run.
     * Intended for a migration or setup command, mirroring
     * {@see DatabaseSagaStateStorage::installSchema()}.
     */
    public function installSchema(): void
    {
        match ($this->connection->driver()) {
            Driver::SQLite => $this->connection->execute(
                'CREATE TABLE IF NOT EXISTS workflow_instances (
                    id TEXT PRIMARY KEY,
                    definition_id TEXT NOT NULL,
                    definition_version INTEGER NOT NULL,
                    current_state TEXT NOT NULL,
                    context TEXT NOT NULL,
                    version INTEGER NOT NULL,
                    status TEXT NOT NULL,
                    started_at TEXT NOT NULL,
                    completed_at TEXT,
                    started_by TEXT NOT NULL,
                    timeout_at TEXT
                )',
            ),
            Driver::MySQL => $this->connection->execute(
                'CREATE TABLE IF NOT EXISTS workflow_instances (
                    id VARCHAR(255) NOT NULL PRIMARY KEY,
                    definition_id VARCHAR(255) NOT NULL,
                    definition_version INT NOT NULL,
                    current_state VARCHAR(255) NOT NULL,
                    context LONGTEXT NOT NULL,
                    version INT NOT NULL,
                    status VARCHAR(32) NOT NULL,
                    started_at DATETIME NOT NULL,
                    completed_at DATETIME NULL,
                    started_by VARCHAR(255) NOT NULL,
                    timeout_at DATETIME NULL,
                    INDEX idx_workflow_instances_timeout (status, timeout_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
            ),
            Driver::PostgreSQL => $this->connection->execute(
                'CREATE TABLE IF NOT EXISTS workflow_instances (
                    id TEXT PRIMARY KEY,
                    definition_id TEXT NOT NULL,
                    definition_version INTEGER NOT NULL,
                    current_state TEXT NOT NULL,
                    context TEXT NOT NULL,
                    version INTEGER NOT NULL,
                    status TEXT NOT NULL,
                    started_at TIMESTAMP NOT NULL,
                    completed_at TIMESTAMP,
                    started_by TEXT NOT NULL,
                    timeout_at TIMESTAMP
                )',
            ),
        };
    }
}
