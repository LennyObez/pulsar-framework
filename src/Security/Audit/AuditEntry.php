<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use DateTimeImmutable;
use JsonException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Crypto\Hmac;
use SodiumException;

use function hash_equals;
use function json_encode;
use function sodium_bin2hex;
use function sodium_crypto_generichash;
use function sprintf;
use function strlen;
use function substr;

/**
 * Immutable audit log entry with HMAC chain for tamper evidence.
 *
 * Each entry's HMAC covers all fields plus the previous entry's HMAC,
 * creating a chain where modifying or deleting any entry invalidates all
 * subsequent HMACs.
 * @api
 */
#[Api(since: '1.0.0')]
readonly class AuditEntry
{
    /**
     * @param array<string, mixed> $metadata
     * @param string $kid Key identifier: empty for legacy entries created before kid tracking
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
     * Sentinel key replacing `metadata` when the supplied bag contains
     * non-encodable values (resources, closures, recursive structures).
     *
     * F9.13: throwing `JsonException` out of `create()` poisoned the
     * audit chain — the caller's mutex was released without a chain
     * advance, the operator lost the audit trail for that operation,
     * and downstream logic continued without a marker. The graceful
     * degradation is to write a synthesised entry whose metadata only
     * carries a serialisation-error indicator so the chain still
     * advances and the incident itself is auditable.
     */
    public const string SERIALIZATION_ERROR_KEY = 'serialization_error';

    /**
     * Create a new audit entry and compute its HMAC.
     *
     * The kid is always derived from the actual key bytes; callers cannot
     * accidentally omit or forge it.
     *
     * F9.13: when `$metadata` contains values `json_encode` cannot
     * handle (resources, closures, recursive structures), we no longer
     * propagate `JsonException`. The entry is instead built with a
     * `metadata` bag that records the serialisation failure, preserving
     * the audit chain. Callers that need to flag this to operators can
     * detect it via the {@see SERIALIZATION_ERROR_KEY} sentinel.
     *
     * @param array<string, mixed> $metadata
     *
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
        // Compute kid from the actual key bytes: 16 hex chars (64 bits)
        // Uses minimum generichash output (16 bytes) then takes first 16 hex chars
        $kid = substr(sodium_bin2hex(sodium_crypto_generichash($auditKey, '', SODIUM_CRYPTO_GENERICHASH_BYTES_MIN)), 0, 16);

        try {
            $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            // Replace the metadata with a sentinel describing the
            // failure so the entry can still be written. The HMAC is
            // computed over the sentinel, so verification on a future
            // read sees a self-consistent entry.
            //
            // SEC-AUDIT-02: the previous code swallowed the JsonException
            // silently, leaving the operator with no signal that an audit
            // entry was emitted with degraded metadata. Trigger a
            // user-warning so log scrapers, set_error_handler hooks, and
            // observability sinks (Pulsar Studio, Sentry, Datadog) all see
            // a measurable event each time metadata is dropped on the
            // floor. This is observability, not failure — the audit chain
            // remains self-consistent because the HMAC covers the sentinel.
            @trigger_error(
                sprintf(
                    'AuditEntry::create() metadata serialization failed for action "%s" (event=%s): %s',
                    $action,
                    $event->value,
                    $e->getMessage(),
                ),
                E_USER_WARNING,
            );

            $metadata = [self::SERIALIZATION_ERROR_KEY => $e->getMessage()];
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // The sentinel only contains a string + key, so encoding it
            // again must succeed. If it somehow returned false (out of
            // memory, etc.), fall back to the most-conservative form.
            if ($metadataJson === false) {
                $metadataJson = '{"' . self::SERIALIZATION_ERROR_KEY . '":"unrenderable"}';
                $metadata = [self::SERIALIZATION_ERROR_KEY => 'unrenderable'];
            }
        }

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
