<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Internal\Storage;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
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
}
