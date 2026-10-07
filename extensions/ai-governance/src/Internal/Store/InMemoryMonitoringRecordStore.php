<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringRecordStoreInterface;
use Pulsar\Extension\AiGovernance\Dto\MonitoringRecord;

use function array_reverse;
use function array_slice;
use function count;

/**
 * In-memory retention of monitoring results, for tests and for local development.
 *
 * It is not the default binding and it must not become one. Clause 9.1 asks for
 * retained evidence of monitoring results, and a store that empties itself when
 * the worker recycles retains nothing an assessor can be shown — the exact
 * substitution {@see DbMonitoringRecordStore} exists to end.
 */
#[Internal(reason: 'Development store; production deployments retain monitoring results in the database')]
final class InMemoryMonitoringRecordStore implements MonitoringRecordStoreInterface
{
    /** @var array<string, list<MonitoringRecord>> Oldest first, keyed by model id */
    private array $records = [];

    #[Override]
    public function record(MonitoringRecord $record): void
    {
        $this->records[$record->modelId][] = $record;
    }

    #[Override]
    public function forModel(string $modelId, int $limit = 100): array
    {
        $held = $this->records[$modelId] ?? [];

        return array_slice(array_reverse($held), 0, $limit);
    }

    #[Override]
    public function countForModel(string $modelId): int
    {
        return count($this->records[$modelId] ?? []);
    }

    #[Override]
    public function purgeForModel(string $modelId): int
    {
        $removed = count($this->records[$modelId] ?? []);

        unset($this->records[$modelId]);

        return $removed;
    }
}
