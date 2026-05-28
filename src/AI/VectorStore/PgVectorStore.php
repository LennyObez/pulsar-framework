<?php

declare(strict_types=1);

namespace Pulsar\AI\VectorStore;

use Override;
use Pulsar\AI\Exception\AiException;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Support\Coerce;

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
 * PostgreSQL pgvector-based vector store.
 *
 * Requires the pgvector extension (CREATE EXTENSION vector) and
 * a table with the following schema:
 *
 * CREATE TABLE {table} (
 *     id TEXT PRIMARY KEY,
 *     embedding vector({dimensions}),
 *     content TEXT NOT NULL DEFAULT '',
 *     metadata JSONB NOT NULL DEFAULT '{}',
 *     created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
 * );
 *
 * CREATE INDEX ON {table} USING ivfflat (embedding {operator}) WITH (lists = 100);
 */
#[Internal(reason: 'VectorStore implementation; use VectorStoreInterface')]
final readonly class PgVectorStore implements VectorStoreInterface
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
        $vectorLiteral = $this->toVectorLiteral($vector);

        $distanceExpr = match ($this->metric) {
            DistanceMetric::Cosine => sprintf('1 - (embedding <=> :query_vec::vector)'),
            DistanceMetric::L2 => sprintf('-(embedding <-> :query_vec::vector)'),
            DistanceMetric::InnerProduct => sprintf('(embedding <#> :query_vec::vector) * -1'),
        };

        $orderExpr = match ($this->metric) {
            DistanceMetric::Cosine => 'embedding <=> :query_vec_order::vector',
            DistanceMetric::L2 => 'embedding <-> :query_vec_order::vector',
            DistanceMetric::InnerProduct => 'embedding <#> :query_vec_order::vector',
        };

        $whereClause = '';
        $bindings = [
            ':query_vec' => $vectorLiteral,
            ':query_vec_order' => $vectorLiteral,
            ':limit' => $limit,
        ];

        if ($filter !== []) {
            $conditions = [];
            $i = 0;

            /** @var mixed $value */
            foreach ($filter as $key => $value) {
                self::assertSafeIdentifier($key);
                $paramName = ':filter_' . $i;
                $conditions[] = sprintf("metadata->>'%s' = %s", $key, $paramName);
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
            $metadataRaw = Coerce::string($row->get('metadata'));
            /** @var mixed $metadataDecoded */
            $metadataDecoded = $metadataRaw === '' ? null : json_decode($metadataRaw, true);
            /** @var array<string, mixed> $metadata */
            $metadata = is_array($metadataDecoded) ? $metadataDecoded : [];

            return new SearchResult(
                id: Coerce::string($row->get('id')),
                score: Coerce::float($row->get('score'), 0.0),
                content: Coerce::string($row->get('content')),
                metadata: $metadata,
            );
        });
    }

    #[Override]
    public function upsert(string $id, array $vector, string $content, array $metadata = []): void
    {
        $vectorLiteral = $this->toVectorLiteral($vector);
        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $sql = sprintf(
            'INSERT INTO %s (id, embedding, content, metadata) VALUES (:id, :embedding::vector, :content, :metadata::jsonb) ON CONFLICT (id) DO UPDATE SET embedding = EXCLUDED.embedding, content = EXCLUDED.content, metadata = EXCLUDED.metadata',
            $this->table,
        );

        $this->connection->execute($sql, [
            ':id' => $id,
            ':embedding' => $vectorLiteral,
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
            $conditions[] = sprintf("metadata->>'%s' = %s", $key, $paramName);
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

            return Coerce::int($first->get('cnt'), 0);
        }

        $conditions = [];
        $bindings = [];
        $i = 0;

        /** @var mixed $value */
        foreach ($filter as $key => $value) {
            self::assertSafeIdentifier($key);
            $paramName = ':filter_' . $i;
            $conditions[] = sprintf("metadata->>'%s' = %s", $key, $paramName);
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
     * Convert a float array to a pgvector literal: '[1.0,2.0,3.0]'.
     *
     * @param list<float> $vector
     */
    private function toVectorLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(static fn(float $v): string => sprintf('%.8f', $v), $vector)) . ']';
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
