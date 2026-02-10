<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Storage;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;

/**
 * Database-backed action history store using ConnectionInterface.
 */
#[Internal]
final class DbActionHistoryStore implements ActionHistoryStoreInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    #[Override]
    public function record(ActionHistoryEntry $entry): void
    {
        $this->connection->execute(
            'INSERT INTO admin_action_history (id, action, resource_name, record_id, actor, timestamp, success, detail) VALUES (:id, :action, :resource_name, :record_id, :actor, :timestamp, :success, :detail)',
            [
                'id' => $entry->id,
                'action' => $entry->action,
                'resource_name' => $entry->resourceName,
                'record_id' => $entry->recordId,
                'actor' => $entry->actor,
                'timestamp' => $entry->timestamp,
                'success' => $entry->success ? 1 : 0,
                'detail' => $entry->detail,
            ],
        );
    }

    #[Override]
    public function recent(int $limit = 50): array
    {
        $result = $this->connection->query(
            "SELECT * FROM admin_action_history ORDER BY timestamp DESC LIMIT {$limit}",
        );

        return $this->hydrateAll($result);
    }

    #[Override]
    public function forResource(string $resourceName, int $limit = 50): array
    {
        $result = $this->connection->query(
            "SELECT * FROM admin_action_history WHERE resource_name = :resource ORDER BY timestamp DESC LIMIT {$limit}",
            ['resource' => $resourceName],
        );

        return $this->hydrateAll($result);
    }

    /**
     * @return list<ActionHistoryEntry>
     */
    private function hydrateAll(\Pulsar\Database\Result $result): array
    {
        $entries = [];
        foreach ($result->rows() as $row) {
            $data = $row->toArray();
            $entries[] = new ActionHistoryEntry(
                id: (string) $data['id'],
                action: (string) $data['action'],
                resourceName: (string) $data['resource_name'],
                recordId: $data['record_id'] !== null ? (string) $data['record_id'] : null,
                actor: (string) $data['actor'],
                timestamp: (int) $data['timestamp'],
                success: (bool) $data['success'],
                detail: (string) $data['detail'],
            );
        }
        return $entries;
    }
}
