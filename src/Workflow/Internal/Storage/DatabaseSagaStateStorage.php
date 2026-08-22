<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Internal\Storage;

use DateTimeImmutable;
use JsonException;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;
use Pulsar\Saga\SagaState;
use Pulsar\Saga\SagaStateStorageInterface;
use Pulsar\Saga\SagaStatus;
use Pulsar\Saga\Step\StepResult;

use function array_map;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Durable database-backed {@see SagaStateStorageInterface}.
 *
 * Persists the latest snapshot of each saga's execution state (one row per
 * saga, upserted by id) so an interrupted saga can be resumed after a process
 * restart. Only the orchestrator's accumulated success results are stored —
 * the step output and name — which is all that is needed to rebuild
 * {@see SagaState} (a {@see StepResult} carries a non-serialisable Throwable
 * only on the failure path, which the orchestrator never persists).
 *
 * Schema is created via {@see installSchema()}, intended for a migration or
 * deploy step (kept here so the DDL lives next to the queries).
 */
#[Internal(reason: 'Use SagaStateStorageInterface port')]
final readonly class DatabaseSagaStateStorage implements SagaStateStorageInterface
{
    private const string TABLE = 'saga_states';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Idempotent DDL: create the `saga_states` table. Safe to re-run.
     */
    public function installSchema(): void
    {
        match ($this->connection->driver()) {
            Driver::SQLite => $this->installSqliteSchema(),
            Driver::MySQL => $this->installMysqlSchema(),
            Driver::PostgreSQL => $this->installPostgresSchema(),
        };
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function save(SagaState $state): void
    {
        $bindings = [
            'saga_id' => $state->sagaId,
            'definition_id' => $state->definitionId,
            'definition_version' => $state->definitionVersion,
            'current_step_index' => $state->currentStepIndex,
            'step_results' => $this->encodeStepResults($state->stepResults),
            'status' => $state->status->value,
            'context' => json_encode($state->context, JSON_THROW_ON_ERROR),
            'started_at' => $state->startedAt->format('Y-m-d H:i:s'),
            'completed_at' => $state->completedAt?->format('Y-m-d H:i:s'),
        ];

        $this->connection->execute($this->upsertSql(), $bindings);
    }

    /**
     * @throws JsonException
     */
    #[Override]
    public function findById(string $sagaId): ?SagaState
    {
        $row = $this->connection->query(
            sprintf(
                'SELECT saga_id, definition_id, definition_version, current_step_index, '
                . 'step_results, status, context, started_at, completed_at FROM %s WHERE saga_id = :saga_id',
                self::TABLE,
            ),
            ['saga_id' => $sagaId],
        )->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * @param list<StepResult> $stepResults
     *
     * @throws JsonException
     */
    private function encodeStepResults(array $stepResults): string
    {
        $encoded = array_map(
            static fn(StepResult $r): array => ['stepName' => $r->stepName, 'output' => $r->output],
            $stepResults,
        );

        return json_encode($encoded, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    private function hydrate(Row $row): SagaState
    {
        /** @var list<array{stepName?: string, output?: array<string, mixed>}> $decoded */
        $decoded = json_decode($row->getString('step_results'), true, flags: JSON_THROW_ON_ERROR);

        $stepResults = [];
        foreach ($decoded as $entry) {
            $stepName = $entry['stepName'] ?? null;
            $output = $entry['output'] ?? null;
            // The orchestrator only ever persists successful results.
            $stepResults[] = StepResult::success(
                is_string($stepName) ? $stepName : '',
                is_array($output) ? $output : [],
            );
        }

        /** @var array<string, mixed> $context */
        $context = json_decode($row->getString('context'), true, flags: JSON_THROW_ON_ERROR);

        $completedAtRaw = $row->getNullableString('completed_at');

        return new SagaState(
            sagaId: $row->getString('saga_id'),
            definitionId: $row->getString('definition_id'),
            definitionVersion: $row->getInt('definition_version'),
            currentStepIndex: max(0, $row->getInt('current_step_index')),
            stepResults: $stepResults,
            status: SagaStatus::from($row->getString('status')),
            context: $context,
            startedAt: new DateTimeImmutable($row->getString('started_at')),
            completedAt: $completedAtRaw !== null ? new DateTimeImmutable($completedAtRaw) : null,
        );
    }

    private function upsertSql(): string
    {
        $columns = 'saga_id, definition_id, definition_version, current_step_index, '
            . 'step_results, status, context, started_at, completed_at';
        $values = ':saga_id, :definition_id, :definition_version, :current_step_index, '
            . ':step_results, :status, :context, :started_at, :completed_at';

        return match ($this->connection->driver()) {
            Driver::SQLite => sprintf('INSERT OR REPLACE INTO %s (%s) VALUES (%s)', self::TABLE, $columns, $values),
            Driver::MySQL => sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE '
                . 'definition_id = VALUES(definition_id), definition_version = VALUES(definition_version), '
                . 'current_step_index = VALUES(current_step_index), step_results = VALUES(step_results), '
                . 'status = VALUES(status), context = VALUES(context), '
                . 'started_at = VALUES(started_at), completed_at = VALUES(completed_at)',
                self::TABLE,
                $columns,
                $values,
            ),
            Driver::PostgreSQL => sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (saga_id) DO UPDATE SET '
                . 'definition_id = EXCLUDED.definition_id, definition_version = EXCLUDED.definition_version, '
                . 'current_step_index = EXCLUDED.current_step_index, step_results = EXCLUDED.step_results, '
                . 'status = EXCLUDED.status, context = EXCLUDED.context, '
                . 'started_at = EXCLUDED.started_at, completed_at = EXCLUDED.completed_at',
                self::TABLE,
                $columns,
                $values,
            ),
        };
    }

    private function installSqliteSchema(): void
    {
        $this->connection->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                saga_id TEXT PRIMARY KEY,
                definition_id TEXT NOT NULL,
                definition_version INTEGER NOT NULL,
                current_step_index INTEGER NOT NULL,
                step_results TEXT NOT NULL,
                status TEXT NOT NULL,
                context TEXT NOT NULL,
                started_at TEXT NOT NULL,
                completed_at TEXT
            )',
            self::TABLE,
        ));
    }

    private function installMysqlSchema(): void
    {
        $this->connection->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                saga_id VARCHAR(255) NOT NULL PRIMARY KEY,
                definition_id VARCHAR(255) NOT NULL,
                definition_version INT NOT NULL,
                current_step_index INT NOT NULL,
                step_results LONGTEXT NOT NULL,
                status VARCHAR(32) NOT NULL,
                context LONGTEXT NOT NULL,
                started_at DATETIME NOT NULL,
                completed_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin',
            self::TABLE,
        ));
    }

    private function installPostgresSchema(): void
    {
        $this->connection->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                saga_id TEXT PRIMARY KEY,
                definition_id TEXT NOT NULL,
                definition_version INTEGER NOT NULL,
                current_step_index INTEGER NOT NULL,
                step_results TEXT NOT NULL,
                status TEXT NOT NULL,
                context TEXT NOT NULL,
                started_at TIMESTAMP NOT NULL,
                completed_at TIMESTAMP
            )',
            self::TABLE,
        ));
    }
}
