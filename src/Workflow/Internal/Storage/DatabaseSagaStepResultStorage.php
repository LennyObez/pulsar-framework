<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Internal\Storage;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Workflow\Storage\SagaStepDirection;
use Pulsar\Workflow\Storage\SagaStepResult;
use Pulsar\Workflow\Storage\SagaStepResultStorageInterface;
use Pulsar\Workflow\Storage\SagaStepStatus;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * PDO-based saga step result storage using the Database module.
 *
 * Forward execution rows are append-only. Compensation creates new rows
 * with direction=compensating. Rows are never deleted.
 */
#[Internal(reason: 'Use SagaStepResultStorageInterface port')]
final readonly class DatabaseSagaStepResultStorage implements SagaStepResultStorageInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function record(SagaStepResult $result): void
    {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO saga_step_results (
                    id, instance_id, step_name, step_index, direction,
                    status, idempotency_key, attempts, result_data,
                    error_message, started_at, completed_at
                ) VALUES (
                    :id, :instance_id, :step_name, :step_index, :direction,
                    :status, :idempotency_key, :attempts, :result_data,
                    :error_message, :started_at, :completed_at
                )
                SQL,
            [
                'id' => $result->id,
                'instance_id' => $result->instanceId,
                'step_name' => $result->stepName,
                'step_index' => $result->stepIndex,
                'direction' => $result->direction->value,
                'status' => $result->status->value,
                'idempotency_key' => $result->idempotencyKey,
                'attempts' => $result->attempts,
                'result_data' => $result->resultData !== null
                    ? json_encode($result->resultData, JSON_THROW_ON_ERROR)
                    : null,
                'error_message' => $result->errorMessage,
                'started_at' => $result->startedAt->format('Y-m-d H:i:s'),
                'completed_at' => $result->completedAt?->format('Y-m-d H:i:s'),
            ],
        );
    }

    #[Override]
    public function updateStatus(
        string $id,
        SagaStepStatus $status,
        ?string $errorMessage = null,
    ): void {
        $this->connection->execute(
            <<<'SQL'
                UPDATE saga_step_results
                SET status = :status, error_message = :error_message
                WHERE id = :id
                SQL,
            [
                'status' => $status->value,
                'error_message' => $errorMessage,
                'id' => $id,
            ],
        );
    }

    #[Override]
    public function markCompleted(
        string $id,
        ?array $resultData = null,
    ): void {
        $this->connection->execute(
            <<<'SQL'
                UPDATE saga_step_results
                SET status = :status,
                    result_data = :result_data,
                    completed_at = :completed_at
                WHERE id = :id
                SQL,
            [
                'status' => SagaStepStatus::Completed->value,
                'result_data' => $resultData !== null
                    ? json_encode($resultData, JSON_THROW_ON_ERROR)
                    : null,
                'completed_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );
    }

    #[Override]
    public function getByInstance(string $instanceId): array
    {
        $result = $this->connection->query(
            'SELECT * FROM saga_step_results WHERE instance_id = :instance_id ORDER BY step_index ASC, started_at ASC',
            ['instance_id' => $instanceId],
        );

        return $result->map(fn(Row $row): SagaStepResult => $this->hydrateResult($row));
    }

    #[Override]
    public function getByDirection(string $instanceId, SagaStepDirection $direction): array
    {
        $result = $this->connection->query(
            'SELECT * FROM saga_step_results WHERE instance_id = :instance_id AND direction = :direction ORDER BY step_index ASC, started_at ASC',
            [
                'instance_id' => $instanceId,
                'direction' => $direction->value,
            ],
        );

        return $result->map(fn(Row $row): SagaStepResult => $this->hydrateResult($row));
    }

    #[Override]
    public function findByIdempotencyKey(string $idempotencyKey): ?SagaStepResult
    {
        $result = $this->connection->query(
            'SELECT * FROM saga_step_results WHERE idempotency_key = :idempotency_key',
            ['idempotency_key' => $idempotencyKey],
        );

        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateResult($row);
    }

    #[Override]
    public function incrementAttempts(string $id): void
    {
        $this->connection->execute(
            'UPDATE saga_step_results SET attempts = attempts + 1 WHERE id = :id',
            ['id' => $id],
        );
    }

    private function hydrateResult(Row $row): SagaStepResult
    {
        $resultDataRaw = $row->getNullableString('result_data');

        /** @var array<string, mixed>|null $resultData */
        $resultData = $resultDataRaw !== null
            ? json_decode($resultDataRaw, true, flags: JSON_THROW_ON_ERROR)
            : null;

        $completedAtRaw = $row->getNullableString('completed_at');
        $completedAt = $completedAtRaw !== null
            ? new DateTimeImmutable($completedAtRaw)
            : null;

        return new SagaStepResult(
            id: $row->getString('id'),
            instanceId: $row->getString('instance_id'),
            stepName: $row->getString('step_name'),
            stepIndex: $row->getInt('step_index'),
            direction: SagaStepDirection::from($row->getString('direction')),
            status: SagaStepStatus::from($row->getString('status')),
            idempotencyKey: $row->getNullableString('idempotency_key'),
            attempts: $row->getInt('attempts'),
            resultData: $resultData,
            errorMessage: $row->getNullableString('error_message'),
            startedAt: new DateTimeImmutable($row->getString('started_at')),
            completedAt: $completedAt,
        );
    }
}
