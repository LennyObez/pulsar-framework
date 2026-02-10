<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Envelope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\JobEnvelope;

#[CoversClass(JobEnvelope::class)]
final class JobEnvelopeTest extends TestCase
{
    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $envelope = $this->createEnvelope();

        self::assertSame('job-001', $envelope->id);
        self::assertSame('App\\Jobs\\SendEmail', $envelope->jobClass);
        self::assertSame('{"to":"user@test.com"}', $envelope->payload);
        self::assertSame('default', $envelope->queue);
        self::assertSame('idem-key-001', $envelope->idempotencyKey);
        self::assertSame('corr-001', $envelope->correlationId);
        self::assertSame('trace-001', $envelope->traceId);
        self::assertSame('span-001', $envelope->spanId);
        self::assertSame(1, $envelope->schemaVersion);
        self::assertNull($envelope->keyId);
        self::assertSame(3, $envelope->retryMaxAttempts);
        self::assertSame(BackoffStrategy::Exponential, $envelope->retryBackoffStrategy);
        self::assertSame(1000, $envelope->retryDelayMs);
        self::assertSame('tenant-001', $envelope->tenantId);
        self::assertSame('user-001', $envelope->subjectId);
        self::assertNull($envelope->batchId);
        self::assertNull($envelope->chainIndex);
        self::assertSame(1, $envelope->attempt);
        self::assertSame(1700000000, $envelope->dispatchedAt);
        self::assertFalse($envelope->encrypted);
        self::assertSame([], $envelope->metadata);
    }

    #[Test]
    public function withNextAttemptIncrementsAttempt(): void
    {
        $envelope = $this->createEnvelope(attempt: 1);

        $next = $envelope->withNextAttempt();

        self::assertSame(2, $next->attempt);
        self::assertSame(1, $envelope->attempt);
        self::assertSame($envelope->id, $next->id);
        self::assertSame($envelope->jobClass, $next->jobClass);
    }

    #[Test]
    public function withMetadataMergesNewMetadata(): void
    {
        $envelope = $this->createEnvelope(metadata: ['existing' => 'value']);

        $updated = $envelope->withMetadata(['new_key' => 'new_value']);

        self::assertSame(['existing' => 'value', 'new_key' => 'new_value'], $updated->metadata);
        self::assertSame(['existing' => 'value'], $envelope->metadata);
    }

    #[Test]
    public function withMetadataOverwritesExistingKeys(): void
    {
        $envelope = $this->createEnvelope(metadata: ['key' => 'old']);

        $updated = $envelope->withMetadata(['key' => 'new']);

        self::assertSame(['key' => 'new'], $updated->metadata);
    }

    #[Test]
    public function defaultMetadataIsEmpty(): void
    {
        $envelope = new JobEnvelope(
            id: 'j1',
            jobClass: 'C',
            payload: '{}',
            queue: 'q',
            idempotencyKey: '',
            correlationId: '',
            traceId: null,
            spanId: null,
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 1,
            retryBackoffStrategy: BackoffStrategy::Fixed,
            retryDelayMs: 0,
            tenantId: null,
            subjectId: null,
            batchId: null,
            chainIndex: null,
            attempt: 1,
            dispatchedAt: 0,
            encrypted: false,
        );

        self::assertSame([], $envelope->metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function createEnvelope(int $attempt = 1, array $metadata = []): JobEnvelope
    {
        return new JobEnvelope(
            id: 'job-001',
            jobClass: 'App\\Jobs\\SendEmail',
            payload: '{"to":"user@test.com"}',
            queue: 'default',
            idempotencyKey: 'idem-key-001',
            correlationId: 'corr-001',
            traceId: 'trace-001',
            spanId: 'span-001',
            schemaVersion: 1,
            keyId: null,
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: 1000,
            tenantId: 'tenant-001',
            subjectId: 'user-001',
            batchId: null,
            chainIndex: null,
            attempt: $attempt,
            dispatchedAt: 1700000000,
            encrypted: false,
            metadata: $metadata,
        );
    }
}
