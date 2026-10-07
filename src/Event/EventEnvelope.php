<?php

declare(strict_types=1);

namespace Pulsar\Event;

use InvalidArgumentException;
use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;
use SodiumException;

use function bin2hex;
use function is_array;
use function json_encode;
use function sodium_crypto_generichash;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Envelope wrapping a domain/integration event with metadata and integrity hash.
 *
 * The payload hash is computed from canonical serialization: event type, schema version,
 * and recursively key-sorted JSON payload: protecting the semantic meaning of the event.
 * @api
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
     * @throws SodiumException
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
     * @param array{
     *     event_id?: string,
     *     event_type?: string,
     *     schema_version?: int,
     *     metadata?: array<string, mixed>,
     *     payload?: array<string, mixed>,
     *     origin_module?: string|null,
     *     scope?: string|null,
     * } $data
     *
     * @throws JsonException
     * @throws InvalidArgumentException When a required envelope field (event_type, etc.) is missing or empty.
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $eventType = $data['event_type'] ?? '';

        if ($eventType === '') {
            throw new InvalidArgumentException('EventEnvelope requires a non-empty eventType');
        }

        $payload = $data['payload'] ?? [];
        $schemaVersion = $data['schema_version'] ?? 0;
        $scopeRaw = $data['scope'] ?? null;

        return new self(
            eventId: $data['event_id'] ?? '',
            eventType: $eventType,
            schemaVersion: $schemaVersion,
            metadata: EventMetadata::fromArray($data['metadata'] ?? []),
            payload: $payload,
            payloadHash: self::computeHash($eventType, $schemaVersion, $payload),
            originModule: $data['origin_module'] ?? null,
            scope: $scopeRaw !== null
                ? (EventScope::tryFrom($scopeRaw) ?? EventScope::CrossModule)
                : EventScope::CrossModule,
        );
    }

    /**
     * Compute canonical payload hash using BLAKE2b (libsodium).
     *
     * Includes eventType and schemaVersion in the hash so identical payloads
     * with different types/versions produce different hashes. Per ADR-0006
     * the framework uses libsodium primitives only — `hash('sha256', ...)` is
     * forbidden in security-relevant code. The output is a 32-byte (64 hex)
     * digest, the same length as the previous SHA-256 implementation, so any
     * stored fixed-width column or comparison logic is preserved.
     *
     * @param array<string, mixed> $payload
     *
     * @throws JsonException
     * @throws SodiumException
     */
    private static function computeHash(string $eventType, int $schemaVersion, array $payload): string
    {
        $canonicalInput = self::canonicalize($eventType, $schemaVersion, $payload);

        return bin2hex(sodium_crypto_generichash($canonicalInput, '', 32));
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

        /** @var mixed $value */
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $data = [...$data, $key => self::recursiveKsort($value)];
            }
        }

        return $data;
    }

    private static function defaultRandomizer(): Randomizer
    {
        return new Randomizer(new Secure());
    }
}
