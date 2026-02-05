<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Storage;

use Override;
use PDO;
use Pulsar\Api\Internal;

/**
 * SQLite-backed action history store.
 */
#[Internal]
final class SqliteActionHistoryStore implements ActionHistoryStoreInterface
{
    private bool $initialized = false;

    public function __construct(
        private readonly PDO $pdo,
    ) {}

    #[Override]
    public function record(ActionHistoryEntry $entry): void
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_action_history (id, action, resource_name, record_id, actor, timestamp, success, detail)
             VALUES (:id, :action, :resource_name, :record_id, :actor, :timestamp, :success, :detail)',
        );
        $stmt->execute([
            'id' => $entry->id,
            'action' => $entry->action,
            'resource_name' => $entry->resourceName,
            'record_id' => $entry->recordId,
            'actor' => $entry->actor,
            'timestamp' => $entry->timestamp,
            'success' => $entry->success ? 1 : 0,
            'detail' => $entry->detail,
        ]);
    }

    #[Override]
    public function recent(int $limit = 50): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM admin_action_history ORDER BY timestamp DESC LIMIT :limit',
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    #[Override]
    public function forResource(string $resourceName, int $limit = 50): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM admin_action_history WHERE resource_name = :resource ORDER BY timestamp DESC LIMIT :limit',
        );
        $stmt->bindValue('resource', $resourceName);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function ensureSchema(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_action_history (
                id TEXT PRIMARY KEY,
                action TEXT NOT NULL,
                resource_name TEXT NOT NULL,
                record_id TEXT,
                actor TEXT NOT NULL,
                timestamp INTEGER NOT NULL,
                success INTEGER NOT NULL DEFAULT 1,
                detail TEXT NOT NULL DEFAULT ""
            )',
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_action_history_resource ON admin_action_history (resource_name, timestamp DESC)',
        );

        $this->initialized = true;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<ActionHistoryEntry>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(
            static fn(array $row): ActionHistoryEntry => new ActionHistoryEntry(
                id: (string) $row['id'],
                action: (string) $row['action'],
                resourceName: (string) $row['resource_name'],
                recordId: $row['record_id'] !== null ? (string) $row['record_id'] : null,
                actor: (string) $row['actor'],
                timestamp: (int) $row['timestamp'],
                success: (bool) $row['success'],
                detail: (string) $row['detail'],
            ),
            $rows,
        );
    }
}
