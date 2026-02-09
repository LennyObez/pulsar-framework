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
use SodiumException;

use function strlen;

/**
 * Immutable audit log entry with HMAC chain for tamper evidence.
 *
 * Each entry's HMAC covers all fields plus the previous entry's HMAC,
 * creating a chain where modifying or deleting any entry invalidates all
 * subsequent HMACs.
 */
#[Api]
readonly class AuditEntry
{
    /**
     * @param array<string, mixed> $metadata
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
    ) {}

    /**
     * Create a new audit entry and compute its HMAC.
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
        );

        $expected = Hmac::computeHex($message, $auditKey);

        return hash_equals($expected, $this->hmac);
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
        ];
    }

    /**
     * Build the message string for HMAC computation.
     *
     * Uses length-prefixed encoding for each field to prevent canonicalization
     * collisions. Each field is encoded as `<byte-length>:<value>`, joined by
     * newlines. This is unambiguous: field boundaries are explicit via
     * byte-length prefix, so no field content can shift the boundary.
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
    ): string {
        $fields = [$id, $event, $outcome, $actor, $action, $resource, $timestamp, $metadataJson, $previousHmac];
        $parts = [];
        foreach ($fields as $field) {
            $parts[] = strlen($field) . ':' . $field;
        }
        return implode("\n", $parts);
    }
}
