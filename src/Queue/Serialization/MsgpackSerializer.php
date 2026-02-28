<?php

declare(strict_types=1);

namespace Pulsar\Queue\Serialization;

use Pulsar\Api\Internal;
use Pulsar\Queue\Exception\QueueException;

use function extension_loaded;
use function is_array;
use function msgpack_pack;
use function msgpack_unpack;

/**
 * MessagePack-based job payload serializer for high-throughput workloads.
 *
 * Requires the `msgpack` PHP extension. Falls back behavior is not provided;
 * callers should check extension availability before selecting this serializer.
 *
 * PHP's native {@see unserialize()} is never used.
 */
#[Internal(reason: 'Optional serializer implementation; depend on SerializerInterface')]
final readonly class MsgpackSerializer implements SerializerInterface
{
    /**
     * @param TypeRegistry          $typeRegistry   Allowlist of deserializable classes.
     * @param SchemaVersionRegistry $schemaRegistry Schema version mappings.
     * @param list<VersionTransformerInterface> $transformers Version migration transformers.
     */
    public function __construct(
        private TypeRegistry $typeRegistry,
        private SchemaVersionRegistry $schemaRegistry,
        private array $transformers = [],
    ) {
        if (!extension_loaded('msgpack')) {
            throw QueueException::driverNotConfigured('msgpack extension is not loaded');
        }
    }

    public function serialize(mixed $data): string
    {
        $payload = match (true) {
            is_array($data) => $data,
            default => ['_value' => $data],
        };

        /** @psalm-suppress UndefinedFunction: ext-msgpack is an optional runtime dependency */
        return msgpack_pack($payload);
    }

    /** @return array<string, mixed> */
    public function deserialize(string $data, string $type): array
    {
        $this->typeRegistry->assertAllowed($type);

        /** @psalm-suppress UndefinedFunction: ext-msgpack is an optional runtime dependency */
        $decoded = msgpack_unpack($data);

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
        return 'application/x-msgpack';
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
