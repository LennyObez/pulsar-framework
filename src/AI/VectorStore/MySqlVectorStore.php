<?php

declare(strict_types=1);

namespace Pulsar\AI\VectorStore;

use Override;
use Pulsar\AI\Exception\AiException;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;

use function array_map;
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
 * MySQL 9.0+ VECTOR type store.
 *
 * Requires MySQL 9.0+ with native VECTOR column support.
 *
 * Expected table schema:
 *
 * CREATE TABLE {table} (
 *     id VARCHAR(255) PRIMARY KEY,
 *     embedding VECTOR({dimensions}),
 *     content TEXT NOT NULL,
 *     metadata JSON NOT NULL DEFAULT ('{}'),
 *     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'VectorStore implementation; use VectorStoreInterface')]
final readonly class MySqlVectorStore implements VectorStoreInterface
{
    /**
     * @param int $dimensions Vector dimensions (used by schema tooling and documentation)
     *
     * @throws AiException When the table name contains invalid characters
     */
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'vector_documents',
        public int $dimensions = 1536,
        private DistanceMetric $metric = DistanceMetric::Cosine,
    ) {
        self::assertSafeIdentifier($this->table);
    }

    #[Override]
    public function search(array $vector, int $limit, array $filter = []): array
    {
        $vectorHex = $this->toVectorBinaryLiteral($vector);

        $distanceExpr = match ($this->metric) {
            DistanceMetric::Cosine => sprintf('(1 - DISTANCE(embedding, %s, COSINE))', $vectorHex),
            DistanceMetric::L2 => sprintf('-DISTANCE(embedding, %s, EUCLIDEAN)', $vectorHex),
            DistanceMetric::InnerProduct => sprintf('DISTANCE(embedding, %s, DOT)', $vectorHex),
        };

        $orderExpr = match ($this->metric) {
            DistanceMetric::Cosine => sprintf('DISTANCE(embedding, %s, COSINE)', $vectorHex),
            DistanceMetric::L2 => sprintf('DISTANCE(embedding, %s, EUCLIDEAN)', $vectorHex),
            DistanceMetric::InnerProduct => sprintf('DISTANCE(embedding, %s, DOT) DESC', $vectorHex),
        };

        $whereClause = '';
        $bindings = [':limit' => $limit];

        if ($filter !== []) {
            $conditions = [];
            $i = 0;

            /** @var mixed $value */
            foreach ($filter as $key => $value) {
                self::assertSafeIdentifier($key);
                $paramName = ':filter_' . $i;
                $conditions[] = sprintf("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.%s')) = %s", $key, $paramName);
                $bindings[$paramName] = is_scalar($value) ? (string) $value : '';
                $i++;
            }

            $whereClause = 'WHERE ' . implode(' AND ', $conditions);
        }

        $sql = sprintf(
            'SELECT id, content, metadata, (%s) AS score FROM %s %s ORDER BY %s LIMIT :limit',
            $distanceExpr,
            $this->table,
            $whereClause,
            $orderExpr,
        );

        $result = $this->connection->query($sql, $bindings);

        return $result->map(function (Row $row): SearchResult {
            /** @var mixed $metadataDecoded */
            $metadataDecoded = json_decode($row->getString('metadata'), true);
            /** @var array<string, mixed> $metadata */
            $metadata = is_array($metadataDecoded) ? $metadataDecoded : [];

            return new SearchResult(
                id: $row->getString('id'),
                score: $row->getFloat('score'),
                content: $row->getString('content'),
                metadata: $metadata,
            );
        });
    }

    #[Override]
    public function upsert(string $id, array $vector, string $content, array $metadata = []): void
    {
        $vectorHex = $this->toVectorBinaryLiteral($vector);
        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $sql = sprintf(
            'INSERT INTO %s (id, embedding, content, metadata) VALUES (:id, %s, :content, :metadata) ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), content = VALUES(content), metadata = VALUES(metadata)',
            $this->table,
            $vectorHex,
        );

        $this->connection->execute($sql, [
            ':id' => $id,
            ':content' => $content,
            ':metadata' => $metadataJson,
        ]);
    }

    #[Override]
    public function delete(string $id): void
    {
        $sql = sprintf('DELETE FROM %s WHERE id = :id', $this->table);
        $this->connection->execute($sql, [':id' => $id]);
    }

    #[Override]
    public function clear(array $filter = []): void
    {
        if ($filter === []) {
            $this->connection->execute(sprintf('DELETE FROM %s', $this->table));

            return;
        }

        $conditions = [];
        $bindings = [];
        $i = 0;

        /** @var mixed $value */
        foreach ($filter as $key => $value) {
            self::assertSafeIdentifier($key);
            $paramName = ':filter_' . $i;
            $conditions[] = sprintf("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.%s')) = %s", $key, $paramName);
            $bindings[$paramName] = is_scalar($value) ? (string) $value : '';
            $i++;
        }

        $sql = sprintf('DELETE FROM %s WHERE %s', $this->table, implode(' AND ', $conditions));
        $this->connection->execute($sql, $bindings);
    }

    #[Override]
    public function count(array $filter = []): int
    {
        if ($filter === []) {
            $result = $this->connection->query(sprintf('SELECT COUNT(*) AS cnt FROM %s', $this->table));
            $first = $result->first();

            if ($first === null) {
                return 0;
            }

            return $first->getInt('cnt');
        }

        $conditions = [];
        $bindings = [];
        $i = 0;

        /** @var mixed $value */
        foreach ($filter as $key => $value) {
            self::assertSafeIdentifier($key);
            $paramName = ':filter_' . $i;
            $conditions[] = sprintf("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.%s')) = %s", $key, $paramName);
            $bindings[$paramName] = is_scalar($value) ? (string) $value : '';
            $i++;
        }

        $sql = sprintf('SELECT COUNT(*) AS cnt FROM %s WHERE %s', $this->table, implode(' AND ', $conditions));
        $result = $this->connection->query($sql, $bindings);
        $first = $result->first();

        if ($first === null) {
            return 0;
        }

        return $first->getInt('cnt');
    }

    /**
     * Convert a float array to MySQL VECTOR literal using STRING_TO_VECTOR.
     *
     * @param list<float> $vector
     */
    private function toVectorBinaryLiteral(array $vector): string
    {
        $literal = '[' . implode(',', array_map(static fn(float $v): string => sprintf('%.8f', $v), $vector)) . ']';

        return sprintf("STRING_TO_VECTOR('%s')", $literal);
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
