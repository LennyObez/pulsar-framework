<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Retention;

use Override;
use PDO;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Observability\Metrics\MetricRegistry;

use function time;

/**
 * Enforces retention policy by deleting old events and reclaiming storage.
 *
 * Tracks pruned link counts in studio_meta for evidence chain
 * window verification reporting.
 */
#[Internal]
final readonly class RetentionEnforcer implements RetentionEnforcerInterface
{
    private const int SIZE_DELETION_BATCH_SIZE = 100;

    public function __construct(
        private SqliteEventStore $store,
        private RetentionPolicy $policy,
        private ?MetricRegistry $metricRegistry = null,
    ) {}

    /**
     * Enforce the retention policy.
     *
     * Deletes events older than the max age, then checks storage size.
     * Records the number of pruned chain links in studio_meta.
     *
     * @return array{events_deleted: int, vacuum_run: bool}
     */
    #[Override]
    public function enforce(): array
    {
        $eventsDeleted = 0;

        // 1. Age-based retention
        $cutoff = $this->policy->ageCutoffUs();
        $countBefore = $this->countChainLinks();
        $eventsDeleted += $this->store->deleteOlderThan($cutoff);
        $countAfter = $this->countChainLinks();
        $linksPruned = $countBefore - $countAfter;

        if ($linksPruned > 0) {
            $this->store->incrementMeta('links_pruned', $linksPruned);
        }

        // 2. Size-based retention (iterative deletion of oldest events)
        $maxBytes = $this->policy->maxSizeBytes();
        $iterations = 0;
        $maxIterations = 10;

        while ($this->store->sizeInBytes() > $maxBytes && $iterations < $maxIterations) {
            $countBefore = $this->countChainLinks();
            $deleted = $this->deleteOldestBatch();
            $countAfter = $this->countChainLinks();
            $batchPruned = $countBefore - $countAfter;

            if ($batchPruned > 0) {
                $this->store->incrementMeta('links_pruned', $batchPruned);
            }

            $eventsDeleted += $deleted;
            $iterations++;

            if ($deleted === 0) {
                break;
            }
        }

        // 3. Vacuum if due
        $vacuumRun = false;
        if ($this->shouldVacuum()) {
            $this->store->vacuum();
            $this->store->setMeta('last_vacuum', (string) time());
            $vacuumRun = true;
        }

        $this->metricRegistry?->counter('studio.retention.events_deleted')->increment(value: (float) $eventsDeleted);

        return [
            'events_deleted' => $eventsDeleted,
            'vacuum_run' => $vacuumRun,
        ];
    }

    /**
     * Check if vacuum should run based on the configured interval.
     */
    private function shouldVacuum(): bool
    {
        $lastVacuum = $this->store->getMeta('last_vacuum');

        if ($lastVacuum === null) {
            return true;
        }

        $elapsed = time() - (int) $lastVacuum;
        $intervalSeconds = $this->policy->vacuumIntervalHours * 3600;

        return $elapsed >= $intervalSeconds;
    }

    /**
     * Delete the oldest batch of events.
     */
    private function deleteOldestBatch(): int
    {
        $pdo = $this->store->pdo();
        $stmt = $pdo->prepare(<<<'SQL'
                DELETE FROM studio_events WHERE id IN (
                    SELECT id FROM studio_events ORDER BY timestamp_us ASC LIMIT :batch_size
                )
            SQL);
        $stmt->bindValue(':batch_size', self::SIZE_DELETION_BATCH_SIZE, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Count current chain links.
     */
    private function countChainLinks(): int
    {
        $pdo = $this->store->pdo();
        $stmt = $pdo->query('SELECT COUNT(*) FROM studio_chain');

        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }
}
