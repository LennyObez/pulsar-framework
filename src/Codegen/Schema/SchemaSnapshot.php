<?php

declare(strict_types=1);

namespace Pulsar\Codegen\Schema;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function hash;
use function is_array;
use function is_string;
use function json_encode;
use function ksort;
use function usort;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Serializable snapshot of entity schema metadata for diff comparison.
 *
 * Produces deterministic output: sorted entity keys, sorted property keys,
 * no timestamps. Two equivalent schemas always yield the same hash.
 *
 * @phpstan-type SnapshotArray array{
 *     version: string,
 *     entities: array<string, array<string, mixed>>,
 * }
 */
#[Api(since: '1.0.0')]
final readonly class SchemaSnapshot
{
    /**
     * @param array<string, EntityDefinition> $entities Keyed by table name
     */
    public function __construct(
        public array $entities,
        public string $version,
    ) {}

    /**
     * Serialize to a deterministic array representation.
     *
     * @return SnapshotArray
     */
    #[NoDiscard]
    public function toArray(): array
    {
        $entitiesData = array_map(
            static fn(EntityDefinition $entity): array => $entity->toArray(),
            $this->entities,
        );

        ksort($entitiesData);

        return [
            'version' => $this->version,
            'entities' => $entitiesData,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawEntities = is_array($data['entities'] ?? null) ? $data['entities'] : [];
        $entities = [];

        foreach ($rawEntities as $tableName => $entityData) {
            if (is_string($tableName) && is_array($entityData)) {
                /** @var array<string, mixed> $entityData */
                $entities[$tableName] = EntityDefinition::fromArray($entityData);
            }
        }

        return new self(
            entities: $entities,
            version: is_string($data['version'] ?? null) ? $data['version'] : '1',
        );
    }

    /**
     * Compute a deterministic hash for quick snapshot comparison.
     *
     * Uses SHA-256 over the canonical JSON representation.
     */
    #[NoDiscard]
    public function hash(): string
    {
        $json = json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $json);
    }

    /**
     * @return list<string> Sorted list of entity table names
     */
    #[NoDiscard]
    public function entityNames(): array
    {
        $names = array_map(
            static fn(EntityDefinition $e): string => $e->tableName,
            $this->entities,
        );

        $names = [...$names];
        usort($names, static fn(string $a, string $b): int => $a <=> $b);

        return $names;
    }
}
