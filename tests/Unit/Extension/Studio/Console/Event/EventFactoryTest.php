<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventFactory;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\Payload\LogEntryPayload;
use Pulsar\Observability\Context\CorrelationContext;

#[CoversClass(EventFactory::class)]
final class EventFactoryTest extends TestCase
{
    #[Test]
    public function envelopeCreatesEnvelopeWithCorrectMetadata(): void
    {
        $factory = new EventFactory('local', 'test-host');

        $event = new LogEntryPayload(
            level: 'info',
            message: 'Test',
            channel: 'app',
            context: [],
        );

        $envelope = $factory->envelope($event);

        self::assertInstanceOf(EventEnvelope::class, $envelope);
        self::assertSame(EventType::LogEntry, $envelope->eventType);
        self::assertSame('local', $envelope->appEnv);
        self::assertSame('test-host', $envelope->hostname);
    }

    #[Test]
    public function envelopeUsesProvidedCorrelationContext(): void
    {
        $factory = new EventFactory('staging', 'app-01');

        $event = new LogEntryPayload(
            level: 'error',
            message: 'Error',
            channel: 'system',
            context: [],
        );

        $context = new CorrelationContext(
            requestId: 'req-ctx',
            traceId: 'trace-ctx',
            spanId: 'span-ctx',
            jobId: 'job-ctx',
        );

        $envelope = $factory->envelope($event, $context);

        self::assertSame('req-ctx', $envelope->requestId);
        self::assertSame('trace-ctx', $envelope->traceId);
        self::assertSame('span-ctx', $envelope->spanId);
        self::assertSame('job-ctx', $envelope->jobId);
    }

    #[Test]
    public function envelopeDefaultsToEmptyCorrelationContext(): void
    {
        $factory = new EventFactory('local', 'host');

        $event = new LogEntryPayload(
            level: 'info',
            message: 'Test',
            channel: 'app',
            context: [],
        );

        $envelope = $factory->envelope($event);

        self::assertNull($envelope->requestId);
        self::assertNull($envelope->traceId);
        self::assertNull($envelope->spanId);
        self::assertNull($envelope->jobId);
    }

    #[Test]
    public function createAutoDetectsHostname(): void
    {
        $factory = EventFactory::create('production');

        $event = new LogEntryPayload(
            level: 'info',
            message: 'Test',
            channel: 'app',
            context: [],
        );

        $envelope = $factory->envelope($event);

        self::assertSame('production', $envelope->appEnv);
        self::assertNotEmpty($envelope->hostname);
    }

    #[Test]
    public function envelopeComputesPayloadHash(): void
    {
        $factory = new EventFactory('local', 'host');

        $event = new LogEntryPayload(
            level: 'info',
            message: 'Test',
            channel: 'app',
            context: [],
        );

        $envelope = $factory->envelope($event);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $envelope->payloadHash);
    }
}
