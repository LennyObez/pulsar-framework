<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

final class EventEnvelopeTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $envelope = new EventEnvelope(
            eventId: 'evt123',
            eventType: EventType::HttpRequest,
            schemaVersion: EventVersion::V1,
            timestampUs: 1700000000000000,
            requestId: 'req-1',
            traceId: 'trace-1',
            spanId: 'span-1',
            jobId: null,
            appEnv: 'local',
            hostname: 'test-host',
            payload: ['method' => 'GET'],
            payloadHash: 'abc123hash',
        );

        self::assertSame('evt123', $envelope->eventId);
        self::assertSame(EventType::HttpRequest, $envelope->eventType);
        self::assertSame(EventVersion::V1, $envelope->schemaVersion);
        self::assertSame(1700000000000000, $envelope->timestampUs);
        self::assertSame('req-1', $envelope->requestId);
        self::assertSame('trace-1', $envelope->traceId);
        self::assertSame('span-1', $envelope->spanId);
        self::assertNull($envelope->jobId);
        self::assertSame('local', $envelope->appEnv);
        self::assertSame('test-host', $envelope->hostname);
        self::assertSame(['method' => 'GET'], $envelope->payload);
    }

    #[Test]
    public function canonicalProducesDeterministicString(): void
    {
        $envelope = new EventEnvelope(
            eventId: 'evt123',
            eventType: EventType::HttpRequest,
            schemaVersion: EventVersion::V1,
            timestampUs: 1700000000000000,
            requestId: 'req-1',
            traceId: 'trace-1',
            spanId: 'span-1',
            jobId: null,
            appEnv: 'local',
            hostname: 'test-host',
            payload: [],
            payloadHash: 'hash123',
        );

        $canonical = $envelope->canonical();

        self::assertSame('evt123|http.request|1|1700000000000000|trace-1|hash123', $canonical);
    }

    #[Test]
    public function canonicalHandlesNullTraceId(): void
    {
        $envelope = new EventEnvelope(
            eventId: 'evt456',
            eventType: EventType::Exception,
            schemaVersion: EventVersion::V1,
            timestampUs: 1700000000000000,
            requestId: null,
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'production',
            hostname: 'prod-1',
            payload: [],
            payloadHash: 'hash456',
        );

        $canonical = $envelope->canonical();

        self::assertSame('evt456|exception|1|1700000000000000||hash456', $canonical);
    }
}
