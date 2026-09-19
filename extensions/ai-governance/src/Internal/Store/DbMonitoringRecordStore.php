<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringRecordStoreInterface;
use Pulsar\Extension\AiGovernance\Dto\MonitoringRecord;

/**
 * The retained evidence of monitoring results ISO 42001 Clause 9.1 asks for.
 *
 * The clause has four questions about method and timing and one requirement at
 * the end: *the organization shall retain appropriate documented information as
 * evidence of the results*. Running a hook and handing the result back to the
 * caller answers the four and none of the one, and until this table existed the
 * extension did exactly that — so a deployment could monitor a model
 * continuously and still have nothing to show an auditor, which is
 * indistinguishable from never having monitored it.
 *
 * ROWS ARE APPENDED, never replaced. A monitoring result is an event: the same
 * hook running against the same model tomorrow is a second piece of evidence, not
 * a correction of the first, and the drift Clause 9.1 exists to surface is only
 * visible in the sequence. The surrogate key is what makes that expressible;
 * every other table in this extension is keyed by the identifier its DTO
 * documents as unique.
 */
#[Internal(reason: 'Durable monitoring record store; use MonitoringRecordStoreInterface for access')]
final readonly class DbMonitoringRecordStore implements MonitoringRecordStoreInterface
{
    private const string TABLE = 'ai_monitoring_records';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function record(MonitoringRecord $record): void
    {
        $this->connection->execute(
            'INSERT INTO ' . self::TABLE
                . ' (model_id, hook_name, healthy, message, metrics, observed_at)'
                . ' VALUES (:model_id, :hook_name, :healthy, :message, :metrics, :observed_at)',
            [
                'model_id' => $record->modelId,
                'hook_name' => $record->hookName,
                'healthy' => $record->healthy ? 1 : 0,
                'message' => $record->message,
                'metrics' => StoredValue::encode($record->metrics),
                'observed_at' => StoredValue::instantToStore($record->observedAt),
            ],
        );
    }

    #[Override]
    public function forModel(string $modelId, int $limit = 100): array
    {
        // Ordered by the surrogate key as well as the instant: two hooks run in
        // the same second carry the same `observed_at`, and an assessor reading a
        // history needs the order they were written in rather than whichever the
        // engine happens to return.
        return $this->connection->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE model_id = :model_id'
                . ' ORDER BY observed_at DESC, id DESC'
                . $this->connection->dialect()->compileLimitOffset($limit, null),
            ['model_id' => $modelId],
        )->map(fn(Row $row): MonitoringRecord => $this->hydrate($row));
    }

    #[Override]
    public function countForModel(string $modelId): int
    {
        $row = $this->connection->query(
            'SELECT COUNT(*) AS retained FROM ' . self::TABLE . ' WHERE model_id = :model_id',
            ['model_id' => $modelId],
        )->first();

        return $row?->getInt('retained') ?? 0;
    }

    #[Override]
    public function purgeForModel(string $modelId): int
    {
        return $this->connection->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE model_id = :model_id',
            ['model_id' => $modelId],
        );
    }

    private function hydrate(Row $row): MonitoringRecord
    {
        return new MonitoringRecord(
            modelId: StoredValue::required($row->getString('model_id'), self::TABLE, 'model_id'),
            hookName: StoredValue::required($row->getString('hook_name'), self::TABLE, 'hook_name'),
            healthy: $row->getBool('healthy'),
            message: StoredValue::required($row->getString('message'), self::TABLE, 'message'),
            metrics: StoredValue::map($row->getString('metrics'), self::TABLE, 'metrics'),
            observedAt: StoredValue::instant($row->getString('observed_at'), self::TABLE, 'observed_at'),
        );
    }
}
