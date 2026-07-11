<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Internal\Storage;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;
use Pulsar\Workflow\Storage\TransitionLogInterface;
use Pulsar\Workflow\Storage\TransitionRecord;
use RuntimeException;

use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Append-only transition log backed by the `workflow_transitions` table.
 *
 * Only INSERT operations are performed; rows are never updated or deleted.
 * The full transition history serves as the audit trail and enables
 * state reconstruction.
 */
#[Internal(reason: 'Use TransitionLogInterface port')]
final readonly class DatabaseTransitionLog implements TransitionLogInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function record(TransitionRecord $record): void
    {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO workflow_transitions (
                    id, instance_id, from_state, to_state, transition_name,
                    actor, reason, metadata, instance_version, created_at
                ) VALUES (
                    :id, :instance_id, :from_state, :to_state, :transition_name,
                    :actor, :reason, :metadata, :instance_version, :created_at
                )
                SQL,
            [
                'id' => $record->id,
                'instance_id' => $record->instanceId,
                'from_state' => $record->fromState,
                'to_state' => $record->toState,
                'transition_name' => $record->transitionName,
                'actor' => $record->actor,
                'reason' => $record->reason,
                'metadata' => json_encode($record->metadata, JSON_THROW_ON_ERROR),
                'instance_version' => $record->instanceVersion,
                'created_at' => $record->createdAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    #[Override]
    public function getHistory(string $instanceId): array
    {
        $result = $this->connection->query(
            'SELECT * FROM workflow_transitions WHERE instance_id = :instance_id ORDER BY created_at ASC',
            ['instance_id' => $instanceId],
        );

        return $result->map(fn(Row $row): TransitionRecord => $this->hydrateRecord($row));
    }

    #[Override]
    public function reconstructState(string $instanceId): string
    {
        $result = $this->connection->query(
            'SELECT to_state FROM workflow_transitions WHERE instance_id = :instance_id ORDER BY created_at DESC LIMIT 1',
            ['instance_id' => $instanceId],
        );

        $row = $result->first();

        if ($row === null) {
            throw new RuntimeException(sprintf(
                'Cannot reconstruct state: no transitions found for workflow instance "%s"',
                $instanceId,
            ));
        }

        return $row->getString('to_state');
    }

    private function hydrateRecord(Row $row): TransitionRecord
    {
        /** @var array<string, mixed> $metadata */
        $metadata = json_decode($row->getString('metadata'), true, 512, JSON_THROW_ON_ERROR);

        return new TransitionRecord(
            id: $row->getString('id'),
            instanceId: $row->getString('instance_id'),
            fromState: $row->getString('from_state'),
            toState: $row->getString('to_state'),
            transitionName: $row->getString('transition_name'),
            actor: $row->getString('actor'),
            reason: $row->getNullableString('reason'),
            metadata: $metadata,
            instanceVersion: $row->getInt('instance_version'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }

    /**
     * Idempotent DDL: create the `workflow_transitions` table. Safe to re-run.
     * Intended for a migration or setup command, mirroring
     * {@see DatabaseSagaStateStorage::installSchema()}.
     */
    public function installSchema(): void
    {
        match ($this->connection->driver()) {
            Driver::SQLite => $this->connection->execute(
                'CREATE TABLE IF NOT EXISTS workflow_transitions (
                    id TEXT PRIMARY KEY,
                    instance_id TEXT NOT NULL,
                    from_state TEXT NOT NULL,
                    to_state TEXT NOT NULL,
                    transition_name TEXT NOT NULL,
                    actor TEXT NOT NULL,
                    reason TEXT,
                    metadata TEXT NOT NULL,
                    instance_version INTEGER NOT NULL,
                    created_at TEXT NOT NULL
                )',
            ),
            Driver::MySQL => $this->connection->execute(
                'CREATE TABLE IF NOT EXISTS workflow_transitions (
                    id VARCHAR(255) NOT NULL PRIMARY KEY,
                    instance_id VARCHAR(255) NOT NULL,
                    from_state VARCHAR(255) NOT NULL,
                    to_state VARCHAR(255) NOT NULL,
                    transition_name VARCHAR(255) NOT NULL,
                    actor VARCHAR(255) NOT NULL,
                    reason TEXT NULL,
                    metadata LONGTEXT NOT NULL,
                    instance_version INT NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_workflow_transitions_instance (instance_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
            ),
            Driver::PostgreSQL => $this->connection->execute(
                'CREATE TABLE IF NOT EXISTS workflow_transitions (
                    id TEXT PRIMARY KEY,
                    instance_id TEXT NOT NULL,
                    from_state TEXT NOT NULL,
                    to_state TEXT NOT NULL,
                    transition_name TEXT NOT NULL,
                    actor TEXT NOT NULL,
                    reason TEXT,
                    metadata TEXT NOT NULL,
                    instance_version INTEGER NOT NULL,
                    created_at TIMESTAMP NOT NULL
                )',
            ),
        };
    }
}
