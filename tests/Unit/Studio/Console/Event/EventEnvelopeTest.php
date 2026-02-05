<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event;

use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventEnvelope;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;
use Pulsar\Studio\CorrelationContext;

use function strlen;

#[CoversClass(EventEnvelope::class)]
final class EventEnvelopeTest extends TestCase
{
    #[Test]
    public function wrapCreatesEnvelopeFromConsoleEvent(): void
    {
        $payload = ['command' => 'test:run', 'args' => ['--verbose']];
        $event = $this->createMockConsoleEvent(
            EventType::LogEntry,
            EventVersion::V1,
            $payload,
        );

        $context = new CorrelationContext(
            requestId: 'req-123',
            traceId: 'trace-456',
            spanId: 'span-789',
            jobId: 'job-abc',
        );

        $envelope = EventEnvelope::wrap($event, $context, 'testing', 'localhost');

        self::assertSame(32, strlen($envelope->eventId));
        self::assertSame(EventType::LogEntry, $envelope->eventType);
        self::assertSame(EventVersion::V1, $envelope->schemaVersion);
        self::assertGreaterThan(0, $envelope->timestampUs);
        self::assertSame('req-123', $envelope->requestId);
        self::assertSame('trace-456', $envelope->traceId);
        self::assertSame('span-789', $envelope->spanId);
        self::assertSame('job-abc', $envelope->jobId);
        self::assertSame('testing', $envelope->appEnv);
        self::assertSame('localhost', $envelope->hostname);
        self::assertSame($payload, $envelope->payload);
    }

    #[Test]
    public function wrapComputesPayloadHashCorrectly(): void
    {
        $payload = ['message' => 'Hello, World!'];
        $event = $this->createMockConsoleEvent(
            EventType::Heartbeat,
            EventVersion::V1,
            $payload,
        );

        $context = new CorrelationContext();

        $envelope = EventEnvelope::wrap($event, $context, 'production', 'server-01');

        $expectedJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expectedHash = hash('sha256', $expectedJson);

        self::assertSame($expectedHash, $envelope->payloadHash);
    }

    #[Test]
    public function wrapHandlesNullCorrelationContext(): void
    {
        $event = $this->createMockConsoleEvent(
            EventType::Exception,
            EventVersion::V1,
            ['error' => 'test'],
        );

        $context = new CorrelationContext();

        $envelope = EventEnvelope::wrap($event, $context, 'development', 'dev-box');

        self::assertNull($envelope->requestId);
        self::assertNull($envelope->traceId);
        self::assertNull($envelope->spanId);
        self::assertNull($envelope->jobId);
    }

    #[Test]
    public function canonicalBuildsCorrectString(): void
    {
        $envelope = new EventEnvelope(
            eventId: 'event-id-123',
            eventType: EventType::HttpRequest,
            schemaVersion: EventVersion::V1,
            timestampUs: 1234567890123456,
            requestId: 'req-abc',
            traceId: 'trace-xyz',
            spanId: 'span-def',
            jobId: null,
            appEnv: 'production',
            hostname: 'web-01',
            payload: ['method' => 'GET'],
            payloadHash: 'abc123def456',
        );

        $canonical = $envelope->canonical();

        $expected = 'event-id-123|http.request|1|1234567890123456|trace-xyz|abc123def456';
        self::assertSame($expected, $canonical);
    }

    #[Test]
    public function canonicalHandlesNullTraceId(): void
    {
        $envelope = new EventEnvelope(
            eventId: 'evt-001',
            eventType: EventType::CacheOperation,
            schemaVersion: EventVersion::V1,
            timestampUs: 9999999999999999,
            requestId: null,
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'test',
            hostname: 'cache-server',
            payload: [],
            payloadHash: 'hash-value',
        );

        $canonical = $envelope->canonical();

        $expected = 'evt-001|cache.operation|1|9999999999999999||hash-value';
        self::assertSame($expected, $canonical);
    }

    #[Test]
    public function constructorSetsAllPropertiesCorrectly(): void
    {
        $payload = ['key' => 'value'];

        $envelope = new EventEnvelope(
            eventId: 'custom-id',
            eventType: EventType::JobQueued,
            schemaVersion: EventVersion::V1,
            timestampUs: 1000000,
            requestId: 'request-id',
            traceId: 'trace-id',
            spanId: 'span-id',
            jobId: 'job-id',
            appEnv: 'staging',
            hostname: 'worker-01',
            payload: $payload,
            payloadHash: 'payload-hash',
        );

        self::assertSame('custom-id', $envelope->eventId);
        self::assertSame(EventType::JobQueued, $envelope->eventType);
        self::assertSame(EventVersion::V1, $envelope->schemaVersion);
        self::assertSame(1000000, $envelope->timestampUs);
        self::assertSame('request-id', $envelope->requestId);
        self::assertSame('trace-id', $envelope->traceId);
        self::assertSame('span-id', $envelope->spanId);
        self::assertSame('job-id', $envelope->jobId);
        self::assertSame('staging', $envelope->appEnv);
        self::assertSame('worker-01', $envelope->hostname);
        self::assertSame($payload, $envelope->payload);
        self::assertSame('payload-hash', $envelope->payloadHash);
    }

    #[Test]
    public function wrapGeneratesUniqueEventIds(): void
    {
        $event = $this->createMockConsoleEvent(
            EventType::Heartbeat,
            EventVersion::V1,
            [],
        );

        $context = new CorrelationContext();

        $envelope1 = EventEnvelope::wrap($event, $context, 'test', 'host');
        $envelope2 = EventEnvelope::wrap($event, $context, 'test', 'host');

        self::assertNotSame($envelope1->eventId, $envelope2->eventId);
    }

    #[Test]
    public function wrapGeneratesTimestampInMicroseconds(): void
    {
        $event = $this->createMockConsoleEvent(
            EventType::Heartbeat,
            EventVersion::V1,
            [],
        );

        $context = new CorrelationContext();

        $beforeUs = (int) (microtime(true) * 1_000_000.0);
        $envelope = EventEnvelope::wrap($event, $context, 'test', 'host');
        $afterUs = (int) (microtime(true) * 1_000_000.0);

        self::assertGreaterThanOrEqual($beforeUs, $envelope->timestampUs);
        self::assertLessThanOrEqual($afterUs, $envelope->timestampUs);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createMockConsoleEvent(
        EventType $type,
        EventVersion $version,
        array $payload,
    ): ConsoleEvent {
        return new class ($type, $version, $payload) implements ConsoleEvent {
            /**
             * @param array<string, mixed> $payload
             */
            public function __construct(
                private readonly EventType $type,
                private readonly EventVersion $version,
                private readonly array $payload,
            ) {}

            public function eventType(): EventType
            {
                return $this->type;
            }

            public function schemaVersion(): EventVersion
            {
                return $this->version;
            }

            public function toArray(): array
            {
                return $this->payload;
            }
        };
    }
}
