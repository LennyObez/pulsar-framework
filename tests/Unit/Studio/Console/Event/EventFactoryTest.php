<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventEnvelope;
use Pulsar\Studio\Console\Event\EventFactory;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;
use Pulsar\Studio\CorrelationContext;

use function strlen;

#[CoversClass(EventFactory::class)]
final class EventFactoryTest extends TestCase
{
    #[Test]
    public function constructorSetsAppEnvAndHostname(): void
    {
        $factory = new EventFactory('production', 'server-01');

        $event = $this->createMockEvent();
        $envelope = $factory->envelope($event);

        self::assertSame('production', $envelope->appEnv);
        self::assertSame('server-01', $envelope->hostname);
    }

    #[Test]
    public function createFactoryWithAutoDetectedHostname(): void
    {
        $factory = EventFactory::create('testing');

        $event = $this->createMockEvent();
        $envelope = $factory->envelope($event);

        self::assertSame('testing', $envelope->appEnv);
        // Hostname should be set (either actual hostname or 'unknown')
        self::assertNotEmpty($envelope->hostname);
    }

    #[Test]
    public function envelopeReturnsEventEnvelope(): void
    {
        $factory = new EventFactory('testing', 'localhost');
        $event = $this->createMockEvent();

        $envelope = $factory->envelope($event);

        self::assertInstanceOf(EventEnvelope::class, $envelope);
    }

    #[Test]
    public function envelopeGeneratesUniqueEventId(): void
    {
        $factory = new EventFactory('testing', 'localhost');
        $event = $this->createMockEvent();

        $envelope1 = $factory->envelope($event);
        $envelope2 = $factory->envelope($event);

        self::assertNotSame($envelope1->eventId, $envelope2->eventId);
        self::assertSame(32, strlen($envelope1->eventId)); // 16 bytes = 32 hex chars
    }

    #[Test]
    public function envelopeUsesEventType(): void
    {
        $factory = new EventFactory('testing', 'localhost');

        $httpEvent = $this->createMockEvent(EventType::HttpRequest);
        $dbEvent = $this->createMockEvent(EventType::DatabaseQuery);

        $httpEnvelope = $factory->envelope($httpEvent);
        $dbEnvelope = $factory->envelope($dbEvent);

        self::assertSame(EventType::HttpRequest, $httpEnvelope->eventType);
        self::assertSame(EventType::DatabaseQuery, $dbEnvelope->eventType);
    }

    #[Test]
    public function envelopeUsesSchemaVersion(): void
    {
        $factory = new EventFactory('testing', 'localhost');
        $event = $this->createMockEvent();

        $envelope = $factory->envelope($event);

        self::assertSame(EventVersion::V1, $envelope->schemaVersion);
    }

    #[Test]
    public function envelopeHasTimestampInMicroseconds(): void
    {
        $factory = new EventFactory('testing', 'localhost');
        $event = $this->createMockEvent();

        /** @var float $beforeTime */
        $beforeTime = microtime(true);
        $before = (int) ($beforeTime * 1_000_000.0);
        $envelope = $factory->envelope($event);
        /** @var float $afterTime */
        $afterTime = microtime(true);
        $after = (int) ($afterTime * 1_000_000.0);

        self::assertGreaterThanOrEqual($before, $envelope->timestampUs);
        self::assertLessThanOrEqual($after, $envelope->timestampUs);
    }

    #[Test]
    public function envelopeUsesCorrelationContext(): void
    {
        $factory = new EventFactory('testing', 'localhost');
        $event = $this->createMockEvent();
        $context = new CorrelationContext(
            requestId: 'req-123',
            traceId: 'trace-456',
            spanId: 'span-789',
            jobId: 'job-000',
        );

        $envelope = $factory->envelope($event, $context);

        self::assertSame('req-123', $envelope->requestId);
        self::assertSame('trace-456', $envelope->traceId);
        self::assertSame('span-789', $envelope->spanId);
        self::assertSame('job-000', $envelope->jobId);
    }

    #[Test]
    public function envelopeUsesDefaultContextWhenNullProvided(): void
    {
        $factory = new EventFactory('testing', 'localhost');
        $event = $this->createMockEvent();

        $envelope = $factory->envelope($event, null);

        self::assertNull($envelope->requestId);
        self::assertNull($envelope->traceId);
        self::assertNull($envelope->spanId);
        self::assertNull($envelope->jobId);
    }

    #[Test]
    public function envelopeContainsPayloadFromEvent(): void
    {
        $factory = new EventFactory('testing', 'localhost');
        $event = $this->createMockEvent(EventType::HttpRequest, [
            'method' => 'POST',
            'uri' => '/api/users',
            'status' => 201,
        ]);

        $envelope = $factory->envelope($event);

        self::assertSame('POST', $envelope->payload['method']);
        self::assertSame('/api/users', $envelope->payload['uri']);
        self::assertSame(201, $envelope->payload['status']);
    }

    #[Test]
    public function envelopeComputesPayloadHash(): void
    {
        $factory = new EventFactory('testing', 'localhost');
        $event = $this->createMockEvent();

        $envelope = $factory->envelope($event);

        self::assertNotEmpty($envelope->payloadHash);
        self::assertSame(64, strlen($envelope->payloadHash)); // SHA-256 = 64 hex chars
    }

    #[Test]
    public function samePayloadProducesSameHash(): void
    {
        $factory = new EventFactory('testing', 'localhost');

        $payload = ['key' => 'value', 'number' => 42];
        $event1 = $this->createMockEvent(EventType::HttpRequest, $payload);
        $event2 = $this->createMockEvent(EventType::HttpRequest, $payload);

        $envelope1 = $factory->envelope($event1);
        $envelope2 = $factory->envelope($event2);

        self::assertSame($envelope1->payloadHash, $envelope2->payloadHash);
    }

    #[Test]
    public function differentPayloadProducesDifferentHash(): void
    {
        $factory = new EventFactory('testing', 'localhost');

        $event1 = $this->createMockEvent(EventType::HttpRequest, ['key' => 'value1']);
        $event2 = $this->createMockEvent(EventType::HttpRequest, ['key' => 'value2']);

        $envelope1 = $factory->envelope($event1);
        $envelope2 = $factory->envelope($event2);

        self::assertNotSame($envelope1->payloadHash, $envelope2->payloadHash);
    }

    #[Test]
    public function envelopePreservesAppEnv(): void
    {
        $factory = new EventFactory('staging', 'test-host');
        $event = $this->createMockEvent();

        $envelope = $factory->envelope($event);

        self::assertSame('staging', $envelope->appEnv);
    }

    #[Test]
    public function envelopePreservesHostname(): void
    {
        $factory = new EventFactory('testing', 'my-hostname');
        $event = $this->createMockEvent();

        $envelope = $factory->envelope($event);

        self::assertSame('my-hostname', $envelope->hostname);
    }

    #[Test]
    public function envelopeWithEmptyPayload(): void
    {
        $factory = new EventFactory('testing', 'localhost');
        $event = $this->createMockEvent(EventType::Heartbeat, []);

        $envelope = $factory->envelope($event);

        self::assertSame([], $envelope->payload);
        self::assertNotEmpty($envelope->payloadHash); // Hash of '[]' JSON
    }

    #[Test]
    public function envelopeWithComplexPayload(): void
    {
        $factory = new EventFactory('testing', 'localhost');

        $complexPayload = [
            'nested' => [
                'level1' => [
                    'level2' => [
                        'value' => 'deep',
                    ],
                ],
            ],
            'array' => [1, 2, 3, 4, 5],
            'unicode' => "\u4e2d\u6587",
            'special' => "line1\nline2\ttab",
        ];

        $event = $this->createMockEvent(EventType::HttpRequest, $complexPayload);
        $envelope = $factory->envelope($event);

        self::assertSame($complexPayload, $envelope->payload);
    }

    #[Test]
    public function envelopeWithPartialCorrelationContext(): void
    {
        $factory = new EventFactory('testing', 'localhost');
        $event = $this->createMockEvent();
        $context = new CorrelationContext(
            requestId: 'only-request-id',
        );

        $envelope = $factory->envelope($event, $context);

        self::assertSame('only-request-id', $envelope->requestId);
        self::assertNull($envelope->traceId);
        self::assertNull($envelope->spanId);
        self::assertNull($envelope->jobId);
    }

    #[Test]
    public function factoryCanBeReusedForMultipleEvents(): void
    {
        $factory = new EventFactory('production', 'server-01');

        $envelopes = [];
        for ($i = 0; $i < 10; $i++) {
            $event = $this->createMockEvent(EventType::HttpRequest, ['index' => $i]);
            $envelopes[] = $factory->envelope($event);
        }

        self::assertCount(10, $envelopes);

        // All envelopes should have unique IDs
        $ids = array_map(fn($e) => $e->eventId, $envelopes);
        self::assertCount(10, array_unique($ids));

        // All should have same appEnv and hostname
        foreach ($envelopes as $envelope) {
            self::assertSame('production', $envelope->appEnv);
            self::assertSame('server-01', $envelope->hostname);
        }
    }

    #[Test]
    public function envelopeForDifferentEventTypes(): void
    {
        $factory = new EventFactory('testing', 'localhost');

        $eventTypes = [
            EventType::HttpRequest,
            EventType::HttpResponse,
            EventType::DatabaseQuery,
            EventType::CacheOperation,
            EventType::JobQueued,
            EventType::JobCompleted,
            EventType::Exception,
            EventType::LogEntry,
            EventType::Heartbeat,
        ];

        foreach ($eventTypes as $type) {
            $event = $this->createMockEvent($type);
            $envelope = $factory->envelope($event);

            self::assertSame($type, $envelope->eventType);
        }
    }

    /**
     * Create a mock ConsoleEvent for testing.
     *
     * @param array<string, mixed> $payload
     */
    private function createMockEvent(
        EventType $type = EventType::HttpRequest,
        array $payload = ['method' => 'GET', 'uri' => '/test'],
    ): ConsoleEvent {
        return new class ($type, $payload) implements ConsoleEvent {
            /**
             * @param array<string, mixed> $payload
             */
            public function __construct(
                private readonly EventType $type,
                private readonly array $payload,
            ) {}

            public function eventType(): EventType
            {
                return $this->type;
            }

            public function schemaVersion(): EventVersion
            {
                return EventVersion::V1;
            }

            public function toArray(): array
            {
                return $this->payload;
            }
        };
    }
}
