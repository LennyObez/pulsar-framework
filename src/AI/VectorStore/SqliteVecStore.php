<?php

declare(strict_types=1);

namespace Pulsar\AI\VectorStore;

use Override;
use Pulsar\AI\Exception\AiException;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;

use function implode;
use function is_array;
use function is_scalar;
use function json_decode;
use function json_encode;
use function preg_match;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * SQLite vector store using the sqlite-vec extension.
 *
 * Requires the sqlite-vec extension loaded into SQLite.
 *
 * Uses a virtual table for vector search and a regular table for metadata:
 *
 * CREATE VIRTUAL TABLE {table}_vec USING vec0(
 *     id TEXT PRIMARY KEY,
 *     embedding float[{dimensions}]
 * );
 *
 * CREATE TABLE {table}_meta (
 *     id TEXT PRIMARY KEY,
 *     content TEXT NOT NULL DEFAULT '',
 *     metadata TEXT NOT NULL DEFAULT '{}',
 *     created_at TEXT NOT NULL DEFAULT (datetime('now'))
 * );
 */
#[Internal(reason: 'VectorStore implementation; use VectorStoreInterface')]
final readonly class SqliteVecStore implements VectorStoreInterface
{
    /**
     * @throws AiException When the table name contains invalid characters
     */
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'vector_documents',
        private int $dimensions = 1536,
        private DistanceMetric $metric = DistanceMetric::Cosine,
    ) {
        self::assertSafeIdentifier($this->table);
    }

    /**
     * Create the virtual table and metadata table if they do not exist.
     */
    public function ensureTables(): void
    {
        $this->connection->execute(sprintf(
            'CREATE VIRTUAL TABLE IF NOT EXISTS %s_vec USING vec0(id TEXT PRIMARY KEY, embedding float[%d])',
            $this->table,
            $this->dimensions,
        ));

        $this->connection->execute(sprintf(
            "CREATE TABLE IF NOT EXISTS %s_meta (id TEXT PRIMARY KEY, content TEXT NOT NULL DEFAULT '', metadata TEXT NOT NULL DEFAULT '{}', created_at TEXT NOT NULL DEFAULT (datetime('now')))",
            $this->table,
        ));
    }

    #[Override]
    public function search(array $vector, int $limit, array $filter = []): array
    {
        $vectorJson = $this->toVectorJson($vector);

        // sqlite-vec uses vec_distance_cosine, vec_distance_L2
        $distanceFunc = match ($this->metric) {
            DistanceMetric::Cosine => 'distance',
            DistanceMetric::L2 => 'distance',
            DistanceMetric::InnerProduct => 'distance',
        };

        // Use the KNN query syntax for vec0
        $sql = sprintf(
            'SELECT v.id, v.distance AS dist, m.content, m.metadata FROM %s_vec v JOIN %s_meta m ON v.id = m.id WHERE v.embedding MATCH :query AND k = :limit ORDER BY v.distance',
            $this->table,
            $this->table,
        );

        $bindings = [
            ':query' => $vectorJson,
            ':limit' => $limit,
        ];

        $result = $this->connection->query($sql, $bindings);
        $rows = [];

        foreach ($result->rows as $row) {
            $distance = $row->getFloat('dist');

            // Convert distance to similarity score (1 - distance for cosine/L2)
            $score = match ($this->metric) {
                DistanceMetric::Cosine => 1.0 - $distance,
                DistanceMetric::L2 => -$distance,
                DistanceMetric::InnerProduct => -$distance,
            };

            $metadataDecoded = json_decode($row->getString('metadata'), true);
            /** @var array<string, mixed> $metadata */
            $metadata = is_array($metadataDecoded) ? $metadataDecoded : [];

            // Apply metadata filter in PHP since sqlite-vec doesn't support it natively
            if ($filter !== [] && !$this->matchesFilter($metadata, $filter)) {
                continue;
            }

            $rows[] = new SearchResult(
                id: $row->getString('id'),
                score: $score,
                content: $row->getString('content'),
                metadata: $metadata,
            );
        }

        return $rows;
    }

    #[Override]
    public function upsert(string $id, array $vector, string $content, array $metadata = []): void
    {
        $vectorJson = $this->toVectorJson($vector);
        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->connection->transaction(function (ConnectionInterface $conn) use ($id, $vectorJson, $content, $metadataJson): void {
            // Delete existing entries first (upsert for virtual tables)
            $conn->execute(
                sprintf('DELETE FROM %s_vec WHERE id = :id', $this->table),
                [':id' => $id],
            );

            $conn->execute(
                sprintf('INSERT INTO %s_vec (id, embedding) VALUES (:id, :embedding)', $this->table),
                [':id' => $id, ':embedding' => $vectorJson],
            );

            $conn->execute(
                sprintf('INSERT OR REPLACE INTO %s_meta (id, content, metadata) VALUES (:id, :content, :metadata)', $this->table),
                [':id' => $id, ':content' => $content, ':metadata' => $metadataJson],
            );
        });
    }

    #[Override]
    public function delete(string $id): void
    {
        $this->connection->transaction(function (ConnectionInterface $conn) use ($id): void {
            $conn->execute(
                sprintf('DELETE FROM %s_vec WHERE id = :id', $this->table),
                [':id' => $id],
            );

            $conn->execute(
                sprintf('DELETE FROM %s_meta WHERE id = :id', $this->table),
                [':id' => $id],
            );
        });
    }

    #[Override]
    public function clear(array $filter = []): void
    {
        if ($filter === []) {
            $this->connection->transaction(function (ConnectionInterface $conn): void {
                $conn->execute(sprintf('DELETE FROM %s_vec', $this->table));
                $conn->execute(sprintf('DELETE FROM %s_meta', $this->table));
            });

            return;
        }

        // For filtered deletes, find matching IDs from metadata table
        $conditions = [];
        $bindings = [];
        $i = 0;

        foreach ($filter as $key => $value) {
            self::assertSafeIdentifier($key);
            $paramName = ':filter_' . $i;
            $conditions[] = sprintf("json_extract(metadata, '$.%s') = %s", $key, $paramName);
            $bindings[$paramName] = is_scalar($value) ? (string) $value : '';
            $i++;
        }

        $whereClause = implode(' AND ', $conditions);

        $this->connection->transaction(function (ConnectionInterface $conn) use ($whereClause, $bindings): void {
            $selectSql = sprintf('SELECT id FROM %s_meta WHERE %s', $this->table, $whereClause);
            $result = $conn->query($selectSql, $bindings);

            foreach ($result->rows as $row) {
                $conn->execute(
                    sprintf('DELETE FROM %s_vec WHERE id = :id', $this->table),
                    [':id' => $row->getString('id')],
                );
            }

            $conn->execute(
                sprintf('DELETE FROM %s_meta WHERE %s', $this->table, $whereClause),
                $bindings,
            );
        });
    }

    #[Override]
    public function count(array $filter = []): int
    {
        if ($filter === []) {
            $result = $this->connection->query(sprintf('SELECT COUNT(*) AS cnt FROM %s_meta', $this->table));
            $first = $result->first();

            if ($first === null) {
                return 0;
            }

            return $first->getInt('cnt');
        }

        $conditions = [];
        $bindings = [];
        $i = 0;

        foreach ($filter as $key => $value) {
            self::assertSafeIdentifier($key);
            $paramName = ':filter_' . $i;
            $conditions[] = sprintf("json_extract(metadata, '$.%s') = %s", $key, $paramName);
            $bindings[$paramName] = is_scalar($value) ? (string) $value : '';
            $i++;
        }

        $sql = sprintf('SELECT COUNT(*) AS cnt FROM %s_meta WHERE %s', $this->table, implode(' AND ', $conditions));
        $result = $this->connection->query($sql, $bindings);
        $first = $result->first();

        if ($first === null) {
            return 0;
        }

        return $first->getInt('cnt');
    }

    /**
     * Convert float array to JSON string for sqlite-vec.
     *
     * @param list<float> $vector
     */
    private function toVectorJson(array $vector): string
    {
        return json_encode($vector, JSON_THROW_ON_ERROR);
    }

    /**
     * Check if metadata matches filter criteria.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $filter
     */
    private function matchesFilter(array $metadata, array $filter): bool
    {
        foreach ($filter as $key => $value) {
            $metaVal = $metadata[$key] ?? null;
            $filterVal = is_scalar($value) ? (string) $value : '';
            $metaStr = is_scalar($metaVal) ? (string) $metaVal : '';

            if ($metaVal === null || $metaStr !== $filterVal) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate that a SQL identifier contains only safe characters (CWE-89).
     *
     * @throws AiException When the identifier contains disallowed characters
     */
    private static function assertSafeIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier) !== 1) {
            throw AiException::unsafeIdentifier($identifier);
        }
    }
}
