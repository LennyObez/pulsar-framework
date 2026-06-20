<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Internal;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Fhir\Rest\FhirRepositoryInterface;

use function bin2hex;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;

use const JSON_THROW_ON_ERROR;

/**
 * Production FHIR resource repository backed by the framework database.
 *
 * Resources are stored row-per-resource in `fhir_resources`, keyed by
 * (resource_type, resource_id), with the full FHIR resource serialised as
 * JSON in `content`. Deletes are logical (`is_deleted`), matching FHIR's
 * delete semantics and preserving an audit trail for regulated deployments;
 * a subsequent create/update on the same id resurrects the row and bumps its
 * `version_id`. Writes run inside a transaction so the read-modify-write that
 * computes the next version is atomic.
 *
 * Search resolves the `_id` control parameter in SQL (PK lookup) and applies
 * the remaining FHIR parameters in PHP via {@see FhirSearchMatcher}, keeping
 * match semantics identical to the in-memory backend across all DB drivers.
 */
#[Internal(reason: 'Database-backed FhirRepositoryInterface implementation; resolve the interface from the container')]
final readonly class DatabaseFhirRepository implements FhirRepositoryInterface
{
    private const string SQL_READ = <<<'SQL'
        SELECT content
        FROM fhir_resources
        WHERE resource_type = :type AND resource_id = :id AND is_deleted = 0
        SQL;

    private const string SQL_SEARCH_BASE = <<<'SQL'
        SELECT content
        FROM fhir_resources
        WHERE resource_type = :type AND is_deleted = 0
        SQL;

    public function __construct(private ConnectionInterface $connection) {}

    #[Override]
    public function read(string $resourceType, string $id): ?array
    {
        $row = $this->connection->query(self::SQL_READ, [
            'type' => $resourceType,
            'id' => $id,
        ])->first();

        if ($row === null) {
            return null;
        }

        return $this->decode($row->getString('content'));
    }

    #[Override]
    public function search(string $resourceType, array $parameters = []): array
    {
        $sql = self::SQL_SEARCH_BASE;
        $bindings = ['type' => $resourceType];

        // Push the _id control parameter down to the primary key for an
        // indexed lookup; every other parameter is matched after decoding.
        if (isset($parameters['_id'])) {
            $sql .= ' AND resource_id = :id';
            $bindings['id'] = $parameters['_id'];
        }

        $results = [];

        foreach ($this->connection->query($sql, $bindings)->rows as $row) {
            $resource = $this->decode($row->getString('content'));

            if (FhirSearchMatcher::matches($resource, $parameters)) {
                $results[] = $resource;
            }
        }

        return $results;
    }

    #[Override]
    public function create(string $resourceType, array $resource): array
    {
        /** @var mixed $rawId */
        $rawId = $resource['id'] ?? null;
        $id = is_string($rawId) && $rawId !== '' ? $rawId : bin2hex(random_bytes(8));

        return $this->persist($resourceType, $id, $resource);
    }

    #[Override]
    public function update(string $resourceType, string $id, array $resource): array
    {
        return $this->persist($resourceType, $id, $resource);
    }

    #[Override]
    public function delete(string $resourceType, string $id): bool
    {
        $affected = $this->connection->execute(
            <<<'SQL'
                UPDATE fhir_resources
                SET is_deleted = 1
                WHERE resource_type = :type AND resource_id = :id AND is_deleted = 0
                SQL,
            ['type' => $resourceType, 'id' => $id],
        );

        return $affected > 0;
    }

    /**
     * Atomically upsert a resource, assigning resourceType/id/meta and bumping
     * the version id (resurrecting a logically-deleted row when present).
     *
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function persist(string $resourceType, string $id, array $resource): array
    {
        $resource['id'] = $id;
        $resource['resourceType'] = $resourceType;

        /** @var array<string, mixed> */
        return $this->connection->transaction(
            function (ConnectionInterface $connection) use ($resourceType, $id, $resource): array {
                $existing = $connection->query(
                    'SELECT version_id FROM fhir_resources WHERE resource_type = :type AND resource_id = :id',
                    ['type' => $resourceType, 'id' => $id],
                )->first();

                $version = $existing === null ? 1 : $existing->getInt('version_id') + 1;
                $lastUpdated = new DateTimeImmutable()->format('Y-m-d\TH:i:s.vP');

                $resource['meta'] = [
                    'versionId' => (string) $version,
                    'lastUpdated' => $lastUpdated,
                ];

                $content = json_encode($resource, JSON_THROW_ON_ERROR);

                if ($existing === null) {
                    $connection->execute(
                        <<<'SQL'
                            INSERT INTO fhir_resources
                                (resource_type, resource_id, version_id, last_updated, content, is_deleted)
                            VALUES (:type, :id, :version, :last_updated, :content, 0)
                            SQL,
                        [
                            'type' => $resourceType,
                            'id' => $id,
                            'version' => $version,
                            'last_updated' => $lastUpdated,
                            'content' => $content,
                        ],
                    );
                } else {
                    $connection->execute(
                        <<<'SQL'
                            UPDATE fhir_resources
                            SET version_id = :version, last_updated = :last_updated, content = :content, is_deleted = 0
                            WHERE resource_type = :type AND resource_id = :id
                            SQL,
                        [
                            'version' => $version,
                            'last_updated' => $lastUpdated,
                            'content' => $content,
                            'type' => $resourceType,
                            'id' => $id,
                        ],
                    );
                }

                return $resource;
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
