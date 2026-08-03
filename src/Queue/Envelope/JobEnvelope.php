<?php

declare(strict_types=1);

namespace Pulsar\Queue\Envelope;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable envelope carrying all metadata for a queued job.
 *
 * The envelope wraps the serialized payload with tracing context,
 * retry policy, encryption metadata, and tenant/subject identification
 * required for regulated job processing.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class JobEnvelope
{
    /**
     * @param string               $id                 Unique job identifier.
     * @param string               $jobClass           Fully-qualified class name of the job.
     * @param string               $payload            Serialized job payload.
     * @param string               $queue              Target queue name.
     * @param string               $idempotencyKey     Deduplication key.
     * @param string               $correlationId      Distributed tracing correlation ID.
     * @param ?string              $traceId            OpenTelemetry trace ID.
     * @param ?string              $spanId             OpenTelemetry span ID.
     * @param int                  $schemaVersion      Payload schema version.
     * @param ?string              $keyId              Encryption key identifier (null if unencrypted).
     * @param int                  $retryMaxAttempts   Maximum number of retry attempts.
     * @param BackoffStrategy      $retryBackoffStrategy Backoff strategy for retries.
     * @param int                  $retryDelayMs       Base delay in milliseconds between retries.
     * @param ?string              $tenantId           Tenant identifier for multi-tenant isolation.
     * @param ?string              $subjectId          Identity of the actor who dispatched the job.
     * @param ?string              $batchId            Batch identifier for grouped jobs.
     * @param ?int                 $chainIndex         Position in a job chain (null if not chained).
     * @param int                  $attempt            Current attempt number (1-based).
     * @param int                  $dispatchedAt       Unix timestamp when the job was dispatched.
     * @param bool                 $encrypted          Whether the payload is encrypted.
     * @param array<string, mixed> $metadata           Extensible metadata bag.
     */
    public function __construct(
        public string $id,
        public string $jobClass,
        public string $payload,
        public string $queue,
        public string $idempotencyKey,
        public string $correlationId,
        public ?string $traceId,
        public ?string $spanId,
        public int $schemaVersion,
        public ?string $keyId,
        public int $retryMaxAttempts,
        public BackoffStrategy $retryBackoffStrategy,
        public int $retryDelayMs,
        public ?string $tenantId,
        public ?string $subjectId,
        public ?string $batchId,
        public ?int $chainIndex,
        public int $attempt,
        public int $dispatchedAt,
        public bool $encrypted,
        public array $metadata = [],
    ) {}

    /**
     * Create a new envelope with an incremented attempt number.
     */
    #[NoDiscard]
    public function withNextAttempt(): self
    {
        return clone($this, ['attempt' => $this->attempt + 1]);
    }

    /**
     * Create a new envelope with updated metadata.
     *
     * @param array<string, mixed> $metadata
     *
     */
    #[NoDiscard]
    public function withMetadata(array $metadata): self
    {
        return clone($this, ['metadata' => [...$this->metadata, ...$metadata]]);
    }
}
