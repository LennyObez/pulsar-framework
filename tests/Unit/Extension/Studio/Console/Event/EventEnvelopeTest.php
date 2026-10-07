<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\LogEntryPayload;
use Pulsar\Observability\Context\CorrelationContext;

use function strlen;

#[CoversClass(EventEnvelope::class)]
final class EventEnvelopeTest extends TestCase
{
    #[Test]
    public function canonicalIncludesAllKeyFields(): void
    {
        $envelope = new EventEnvelope(
            eventId: 'evt-123',
            eventType: EventType::HttpRequest,
            schemaVersion: EventVersion::V1,
            timestampUs: 1700000000000000,
            requestId: 'req-1',
            traceId: 'trace-abc',
            spanId: 'span-1',
            jobId: null,
            appEnv: 'local',
            hostname: 'test-host',
            payload: ['method' => 'GET'],
            payloadHash: 'hash123',
        );

        $canonical = $envelope->canonical();

        self::assertStringContainsString('evt-123', $canonical);
        self::assertStringContainsString('http.request', $canonical);
        self::assertStringContainsString('1', $canonical);
        self::assertStringContainsString('1700000000000000', $canonical);
        self::assertStringContainsString('trace-abc', $canonical);
        self::assertStringContainsString('hash123', $canonical);
    }

    #[Test]
    public function canonicalHandlesNullTraceId(): void
    {
        $envelope = new EventEnvelope(
            eventId: 'evt-1',
            eventType: EventType::LogEntry,
            schemaVersion: EventVersion::V1,
            timestampUs: 1000,
            requestId: null,
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'local',
            hostname: 'host',
            payload: [],
            payloadHash: 'h',
        );

        $canonical = $envelope->canonical();

        // Null traceId should produce empty string in canonical
        self::assertStringContainsString('||', $canonical);
    }

    #[Test]
    public function wrapCreatesEnvelopeFromConsoleEvent(): void
    {
        $event = new LogEntryPayload(
            level: 'info',
            message: 'Test message',
            channel: 'app',
            context: [],
        );

        $context = new CorrelationContext(
            requestId: 'req-wrap',
            traceId: 'trace-wrap',
        );

        $envelope = EventEnvelope::wrap($event, $context, 'local', 'test-host');

        self::assertSame(EventType::LogEntry, $envelope->eventType);
        self::assertSame(EventVersion::V1, $envelope->schemaVersion);
        self::assertSame('req-wrap', $envelope->requestId);
        self::assertSame('trace-wrap', $envelope->traceId);
        self::assertSame('local', $envelope->appEnv);
        self::assertSame('test-host', $envelope->hostname);
        self::assertSame(64, strlen($envelope->payloadHash));
        self::assertSame(32, strlen($envelope->eventId));
    }
}
