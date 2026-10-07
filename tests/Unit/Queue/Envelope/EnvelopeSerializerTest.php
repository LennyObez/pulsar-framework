<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Envelope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\EnvelopeSerializer;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Exception\QueueException;

use function assert;
use function is_string;

#[CoversClass(EnvelopeSerializer::class)]
final class EnvelopeSerializerTest extends TestCase
{
    private EnvelopeSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new EnvelopeSerializer();
    }

    #[Test]
    public function serializeAndDeserializeRoundTrip(): void
    {
        $envelope = $this->createEnvelope();

        $json = $this->serializer->serialize($envelope);
        $restored = $this->serializer->deserialize($json);

        self::assertSame($envelope->id, $restored->id);
        self::assertSame($envelope->jobClass, $restored->jobClass);
        self::assertSame($envelope->payload, $restored->payload);
        self::assertSame($envelope->queue, $restored->queue);
        self::assertSame($envelope->idempotencyKey, $restored->idempotencyKey);
        self::assertSame($envelope->correlationId, $restored->correlationId);
        self::assertSame($envelope->traceId, $restored->traceId);
        self::assertSame($envelope->spanId, $restored->spanId);
        self::assertSame($envelope->schemaVersion, $restored->schemaVersion);
        self::assertSame($envelope->keyId, $restored->keyId);
        self::assertSame($envelope->retryMaxAttempts, $restored->retryMaxAttempts);
        self::assertSame($envelope->retryBackoffStrategy, $restored->retryBackoffStrategy);
        self::assertSame($envelope->retryDelayMs, $restored->retryDelayMs);
        self::assertSame($envelope->tenantId, $restored->tenantId);
        self::assertSame($envelope->subjectId, $restored->subjectId);
        self::assertSame($envelope->batchId, $restored->batchId);
        self::assertSame($envelope->chainIndex, $restored->chainIndex);
        self::assertSame($envelope->attempt, $restored->attempt);
        self::assertSame($envelope->dispatchedAt, $restored->dispatchedAt);
        self::assertSame($envelope->encrypted, $restored->encrypted);
        self::assertSame($envelope->metadata, $restored->metadata);
    }

    #[Test]
    public function serializeProducesValidJson(): void
    {
        $envelope = $this->createEnvelope();

        $json = $this->serializer->serialize($envelope);

        self::assertJson($json);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);
        self::assertSame('job-001', $decoded['id']);
    }

    #[Test]
    public function deserializeWithNullableFieldsHandlesNull(): void
    {
        $envelope = new JobEnvelope(
            id: 'j1',
            jobClass: 'C',
            payload: '{}',
            queue: 'q',
            idempotencyKey: 'k',
            correlationId: 'c',
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
            dispatchedAt: 1700000000,
            encrypted: false,
        );

        $json = $this->serializer->serialize($envelope);
        $restored = $this->serializer->deserialize($json);

        self::assertNull($restored->traceId);
        self::assertNull($restored->spanId);
        self::assertNull($restored->keyId);
        self::assertNull($restored->tenantId);
        self::assertNull($restored->subjectId);
        self::assertNull($restored->batchId);
        self::assertNull($restored->chainIndex);
    }

    #[Test]
    public function deserializeThrowsForMissingRequiredField(): void
    {
        $json = json_encode(['id' => 'j1']);
        assert(is_string($json));

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageIsOrContains('missing required field');

        $this->serializer->deserialize($json);
    }

    #[Test]
    public function deserializeThrowsForInvalidBackoffStrategy(): void
    {
        $data = $this->createFullData();
        $data['retryBackoffStrategy'] = 'invalid_strategy';

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageIsOrContains('unknown backoff strategy');

        $this->serializer->deserialize(self::jsonEncode($data));
    }

    #[Test]
    public function deserializeThrowsForNonObjectJson(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessageIsOrContains('not an object');

        $this->serializer->deserialize('"just a string"');
    }

    #[Test]
    public function deserializeThrowsForInvalidStringField(): void
    {
        $data = $this->createFullData();
        $data['id'] = 12345;

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageIsOrContains('must be a string');

        $this->serializer->deserialize(self::jsonEncode($data));
    }

    #[Test]
    public function deserializeThrowsForInvalidIntField(): void
    {
        $data = $this->createFullData();
        $data['schemaVersion'] = 'not-an-int';

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageIsOrContains('must be an integer');

        $this->serializer->deserialize(self::jsonEncode($data));
    }

    #[Test]
    public function deserializeThrowsForInvalidBoolField(): void
    {
        $data = $this->createFullData();
        $data['encrypted'] = 'not-a-bool';

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageIsOrContains('must be a boolean');

        $this->serializer->deserialize(self::jsonEncode($data));
    }

    #[Test]
    public function deserializeHandlesMetadata(): void
    {
        $data = $this->createFullData();
        $data['metadata'] = ['key' => 'value', 'nested' => ['a' => 1]];

        $envelope = $this->serializer->deserialize(self::jsonEncode($data));

        self::assertSame('value', $envelope->metadata['key']);
    }

    #[Test]
    public function deserializeHandlesMissingMetadata(): void
    {
        $data = $this->createFullData();
        unset($data['metadata']);

        $envelope = $this->serializer->deserialize(self::jsonEncode($data));

        self::assertSame([], $envelope->metadata);
    }

    #[Test]
    public function deserializeThrowsForInvalidNullableStringField(): void
    {
        $data = $this->createFullData();
        $data['traceId'] = 12345;

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageIsOrContains('must be a string or null');

        $this->serializer->deserialize(self::jsonEncode($data));
    }

    #[Test]
    public function deserializeThrowsForInvalidChainIndex(): void
    {
        $data = $this->createFullData();
        $data['chainIndex'] = 'not-an-int';

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageIsOrContains('must be an integer or null');

        $this->serializer->deserialize(self::jsonEncode($data));
    }

    /**
     * @return array<string, mixed>
     */
    private function createFullData(): array
    {
        return [
            'id' => 'job-001',
            'jobClass' => 'App\\Jobs\\Test',
            'payload' => '{}',
            'queue' => 'default',
            'idempotencyKey' => 'key-001',
            'correlationId' => 'corr-001',
            'traceId' => null,
            'spanId' => null,
            'schemaVersion' => 1,
            'keyId' => null,
            'retryMaxAttempts' => 3,
            'retryBackoffStrategy' => 'exponential',
            'retryDelayMs' => 1000,
            'tenantId' => null,
            'subjectId' => null,
            'batchId' => null,
            'chainIndex' => null,
            'attempt' => 1,
            'dispatchedAt' => 1700000000,
            'encrypted' => false,
            'metadata' => [],
        ];
    }

    private static function jsonEncode(mixed $data): string
    {
        $json = json_encode($data);
        assert(is_string($json));
        return $json;
    }

    private function createEnvelope(): JobEnvelope
    {
        return new JobEnvelope(
            id: 'job-001',
            jobClass: 'App\\Jobs\\SendEmail',
            payload: '{"to":"user@test.com"}',
            queue: 'default',
            idempotencyKey: 'idem-001',
            correlationId: 'corr-001',
            traceId: 'trace-001',
            spanId: 'span-001',
            schemaVersion: 1,
            keyId: 'key-001',
            retryMaxAttempts: 3,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: 1000,
            tenantId: 'tenant-001',
            subjectId: 'user-001',
            batchId: 'batch-001',
            chainIndex: 2,
            attempt: 1,
            dispatchedAt: 1700000000,
            encrypted: false,
            metadata: ['source' => 'api'],
        );
    }
}
