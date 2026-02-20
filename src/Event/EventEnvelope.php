<?php

declare(strict_types=1);

namespace Pulsar\Event;

use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Event\Exception\EventException;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function bin2hex;
use function hash;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Envelope wrapping a domain/integration event with metadata and integrity hash.
 *
 * The payload hash is computed from canonical serialization: event type, schema version,
 * and recursively key-sorted JSON payload: protecting the semantic meaning of the event.
 */
#[Api(since: '1.0.0')]
final readonly class EventEnvelope
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public int $schemaVersion,
        public EventMetadata $metadata,
        public array $payload,
        public string $payloadHash,
        public ?string $originModule = null,
        public EventScope $scope = EventScope::CrossModule,
    ) {}

    /**
     * Wrap a domain event into an envelope with computed payload hash.
     *
     * @param array<string, mixed> $payload
     *
     * @throws JsonException
     * @throws RandomException
     */
    #[NoDiscard]
    public static function wrap(
        string $eventType,
        int $schemaVersion,
        array $payload,
        EventMetadata $metadata,
        ?Randomizer $randomizer = null,
        ?string $originModule = null,
    ): self {
        $randomizer ??= self::defaultRandomizer();
        $eventId = bin2hex($randomizer->getBytes(16));
        $payloadHash = self::computeHash($eventType, $schemaVersion, $payload);

        return new self(
            eventId: $eventId,
            eventType: $eventType,
            schemaVersion: $schemaVersion,
            metadata: $metadata,
            payload: $payload,
            payloadHash: $payloadHash,
            originModule: $originModule,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'schema_version' => $this->schemaVersion,
            'metadata' => $this->metadata->toArray(),
            'payload' => $this->payload,
            'payload_hash' => $this->payloadHash,
            'origin_module' => $this->originModule,
            'scope' => $this->scope->value,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     * @throws EventException When a required envelope field (event_type, etc.) is missing or empty.
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $metadataData */
        $metadataData = $data['metadata'] ?? [];

        /** @var array<string, mixed> $payload */
        $payload = $data['payload'] ?? [];

        $eventId = $data['event_id'] ?? '';
        $eventType = $data['event_type'] ?? '';
        $schemaVersion = $data['schema_version'] ?? 0;
        $originModule = $data['origin_module'] ?? null;
        $scopeRaw = $data['scope'] ?? null;

        $validEventType = is_string($eventType) ? $eventType : '';

        if ($validEventType === '') {
            throw EventException::missingEnvelopeField('eventType');
        }

        $scope = is_string($scopeRaw) ? (EventScope::tryFrom($scopeRaw) ?? EventScope::CrossModule) : EventScope::CrossModule;

        return new self(
            eventId: is_string($eventId) ? $eventId : '',
            eventType: $validEventType,
            schemaVersion: is_int($schemaVersion) ? $schemaVersion : 0,
            metadata: EventMetadata::fromArray($metadataData),
            payload: $payload,
            payloadHash: self::computeHash(
                $validEventType,
                is_int($schemaVersion) ? $schemaVersion : 0,
                $payload,
            ),
            originModule: is_string($originModule) ? $originModule : null,
            scope: $scope,
        );
    }

    /**
     * Compute canonical payload hash.
     *
     * Includes eventType and schemaVersion in the hash so identical payloads
     * with different types/versions produce different hashes.
     *
     * @param array<string, mixed> $payload
     *
     * @throws JsonException
     */
    private static function computeHash(string $eventType, int $schemaVersion, array $payload): string
    {
        $canonicalInput = self::canonicalize($eventType, $schemaVersion, $payload);

        return hash('sha256', $canonicalInput);
    }

    /**
     * Build canonical input string for hash computation.
     *
     * @param array<string, mixed> $payload
     *
     * @throws JsonException
     */
    private static function canonicalize(string $eventType, int $schemaVersion, array $payload): string
    {
        $sorted = self::recursiveKsort($payload);
        $json = json_encode($sorted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return sprintf('%s|%d|%s', $eventType, $schemaVersion, $json);
    }

    /**
     * Recursively sort array keys at all levels.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function recursiveKsort(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $data[$key] = self::recursiveKsort($value);
            }
        }

        return $data;
    }

    private static function defaultRandomizer(): Randomizer
    {
        /** @var Randomizer|null $randomizer */
        static $randomizer = null;

        return $randomizer ??= new Randomizer(new Secure());
    }
}
