<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use DateTimeImmutable;

use function hash_equals;
use function json_encode;

use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Crypto\Hmac;

use function sodium_bin2hex;
use function sodium_crypto_generichash;

use SodiumException;

use function strlen;
use function substr;

/**
 * Immutable audit log entry with HMAC chain for tamper evidence.
 *
 * Each entry's HMAC covers all fields plus the previous entry's HMAC,
 * creating a chain where modifying or deleting any entry invalidates all
 * subsequent HMACs.
 */
#[Api(since: '1.0.0')]
readonly class AuditEntry
{
    /**
     * @param array<string, mixed> $metadata
     * @param string $kid Key identifier — empty for legacy entries created before kid tracking
     */
    public function __construct(
        public string $id,
        public AuditEvent $event,
        public AuditOutcome $outcome,
        public string $actor,
        public string $action,
        public string $resource,
        public DateTimeImmutable $timestamp,
        public array $metadata,
        public string $previousHmac,
        public string $hmac,
        public string $kid = '',
    ) {}

    /**
     * Create a new audit entry and compute its HMAC.
     *
     * The kid is always derived from the actual key bytes — callers cannot
     * accidentally omit or forge it.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws JsonException
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function create(
        string $id,
        AuditEvent $event,
        AuditOutcome $outcome,
        string $actor,
        string $action,
        string $resource,
        DateTimeImmutable $timestamp,
        array $metadata,
        string $previousHmac,
        string $auditKey,
    ): self {
        // Compute kid from the actual key bytes — 16 hex chars (64 bits)
        // Uses minimum generichash output (16 bytes) then takes first 16 hex chars
        $kid = substr(sodium_bin2hex(sodium_crypto_generichash($auditKey, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MIN)), 0, 16);

        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $message = self::buildMessage(
            $id,
            $event->value,
            $outcome->value,
            $actor,
            $action,
            $resource,
            $timestamp->format('Y-m-d\TH:i:s.uP'),
            $metadataJson,
            $previousHmac,
            $kid,
        );

        $hmac = Hmac::computeHex($message, $auditKey);

        return new self(
            id: $id,
            event: $event,
            outcome: $outcome,
            actor: $actor,
            action: $action,
            resource: $resource,
            timestamp: $timestamp,
            metadata: $metadata,
            previousHmac: $previousHmac,
            hmac: $hmac,
            kid: $kid,
        );
    }

    /**
     * Verify this entry's HMAC is valid for the given audit key.
     *
     * @throws JsonException
     * @throws SodiumException
     */
    public function verify(string $auditKey): bool
    {
        $metadataJson = json_encode($this->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $message = self::buildMessage(
            $this->id,
            $this->event->value,
            $this->outcome->value,
            $this->actor,
            $this->action,
            $this->resource,
            $this->timestamp->format('Y-m-d\TH:i:s.uP'),
            $metadataJson,
            $this->previousHmac,
            $this->kid,
        );

        $expected = Hmac::computeHex($message, $auditKey);

        return hash_equals($expected, $this->hmac);
    }

    /**
     * Build the HMAC message from an existing entry (for external verification).
     *
     * @throws JsonException
     */
    public static function buildMessageFromEntry(self $entry): string
    {
        $metadataJson = json_encode($entry->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::buildMessage(
            $entry->id,
            $entry->event->value,
            $entry->outcome->value,
            $entry->actor,
            $entry->action,
            $entry->resource,
            $entry->timestamp->format('Y-m-d\TH:i:s.uP'),
            $metadataJson,
            $entry->previousHmac,
            $entry->kid,
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'outcome' => $this->outcome->value,
            'actor' => $this->actor,
            'action' => $this->action,
            'resource' => $this->resource,
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s.uP'),
            'metadata' => $this->metadata,
            'previous_hmac' => $this->previousHmac,
            'hmac' => $this->hmac,
            'kid' => $this->kid,
        ];
    }

    /**
     * Build the message string for HMAC computation.
     *
     * Uses length-prefixed encoding for each field to prevent canonicalization
     * collisions. Each field is encoded as `<byte-length>:<value>`, joined by
     * newlines. kid is included as the 10th field to bind the entry to its key.
     */
    private static function buildMessage(
        string $id,
        string $event,
        string $outcome,
        string $actor,
        string $action,
        string $resource,
        string $timestamp,
        string $metadataJson,
        string $previousHmac,
        string $kid,
    ): string {
        $fields = [$id, $event, $outcome, $actor, $action, $resource, $timestamp, $metadataJson, $previousHmac, $kid];
        $parts = [];
        foreach ($fields as $field) {
            $parts[] = strlen($field) . ':' . $field;
        }

        return implode("\n", $parts);
    }
}
