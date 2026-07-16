<?php

declare(strict_types=1);

namespace Pulsar\Queue\Serialization;

use Pulsar\Api\Internal;
use Pulsar\Queue\Exception\QueueException;

use function extension_loaded;
use function is_array;
use function is_object;
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

        /** @psalm-suppress UndefinedFunction ext-msgpack is an optional runtime dependency */
        return (string) msgpack_pack($payload);
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

        // Enforce the same object-free, pure-data contract as JsonSerializer.
        // msgpack (with the default msgpack.php_only=On) can reconstruct arbitrary
        // PHP objects from a crafted payload — unlike json_decode(..., true), which
        // can only ever yield arrays/scalars. Reject any object fail-closed so a
        // tampered queue message can never inject a deserialization gadget through
        // this serializer (CWE-502). Queue payloads are always pure data arrays.
        self::assertObjectFree($decoded, $type);

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

    /**
     * Recursively assert that a decoded payload contains no PHP objects, walking
     * into nested arrays. Fails closed on the first object found: queue payloads
     * are pure data, so any object is a tampered/hostile message.
     *
     * @throws QueueException If an object is present anywhere in the payload.
     */
    private static function assertObjectFree(mixed $value, string $type): void
    {
        if (is_object($value)) {
            throw QueueException::unsafeObjectPayload($type);
        }

        if (is_array($value)) {
            /** @var mixed $item */
            foreach ($value as $item) {
                self::assertObjectFree($item, $type);
            }
        }
    }
}
