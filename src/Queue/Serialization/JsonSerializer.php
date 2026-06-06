<?php

declare(strict_types=1);

namespace Pulsar\Queue\Serialization;

use Pulsar\Api\Internal;
use Pulsar\Queue\Exception\QueueException;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * JSON-based job payload serializer with schema versioning and type safety.
 *
 * Serialized output includes a `_schema_version` header that enables
 * forward-compatible deserialization through {@see VersionTransformerInterface}.
 *
 * PHP's native {@see unserialize()} is never used.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Default serializer implementation; depend on SerializerInterface')]
final readonly class JsonSerializer implements SerializerInterface
{
    /**
     * @param TypeRegistry          $typeRegistry    Allowlist of deserializable classes.
     * @param SchemaVersionRegistry $schemaRegistry  Schema version mappings.
     * @param list<VersionTransformerInterface> $transformers Version migration transformers.
     */
    public function __construct(
        private TypeRegistry $typeRegistry,
        private SchemaVersionRegistry $schemaRegistry,
        private array $transformers = [],
    ) {}

    public function serialize(mixed $data): string
    {
        $payload = match (true) {
            is_array($data) => $data,
            default => ['_value' => $data],
        };

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public function deserialize(string $data, string $type): array
    {
        $this->typeRegistry->assertAllowed($type);

        $decoded = json_decode($data, true, 64, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw QueueException::serializationFailed($type);
        }

        /** @var array<string, mixed> $typed */
        $typed = $decoded;

        /** @var int $payloadVersion */
        $payloadVersion = $typed['_schema_version'] ?? 1;
        $currentVersion = $this->schemaRegistry->currentVersion($type);

        if ($payloadVersion !== $currentVersion) {
            if (!$this->schemaRegistry->validate($type, $payloadVersion)) {
                throw QueueException::incompatibleSchemaVersion($type, $payloadVersion, $currentVersion);
            }

            $typed = $this->migratePayload($type, $typed, $payloadVersion, $currentVersion);
        }

        return $typed;
    }

    public function contentType(): string
    {
        return 'application/json';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws QueueException If no transformer supports the required migration.
     */
    private function migratePayload(string $type, array $data, int $fromVersion, int $toVersion): array
    {
        foreach ($this->transformers as $transformer) {
            if ($transformer->supports($type, $fromVersion, $toVersion)) {
                return $transformer->transform($data, $fromVersion, $toVersion);
            }
        }

        throw QueueException::incompatibleSchemaVersion($type, $fromVersion, $toVersion);
    }
}
