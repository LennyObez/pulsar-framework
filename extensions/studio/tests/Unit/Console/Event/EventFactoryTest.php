<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventFactory;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Observability\Context\CorrelationContext;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function strlen;

final class EventFactoryTest extends TestCase
{
    #[Test]
    public function envelopeCreatesValidEnvelope(): void
    {
        $factory = new EventFactory('local', 'test-host', new Randomizer(new Mt19937(42)));
        $event = $this->createEvent();

        $envelope = $factory->envelope($event);

        self::assertNotEmpty($envelope->eventId);
        self::assertSame(EventType::LogEntry, $envelope->eventType);
        self::assertSame(EventVersion::V1, $envelope->schemaVersion);
        self::assertSame('local', $envelope->appEnv);
        self::assertSame('test-host', $envelope->hostname);
        self::assertGreaterThan(0, $envelope->timestampUs);
    }

    #[Test]
    public function envelopeUsesCorrelationContext(): void
    {
        $factory = new EventFactory('local', 'host1', new Randomizer(new Mt19937(42)));
        $context = new CorrelationContext(
            requestId: 'req-abc',
            traceId: 'trace-xyz',
            spanId: 'span-123',
            jobId: 'job-456',
        );

        $envelope = $factory->envelope($this->createEvent(), $context);

        self::assertSame('req-abc', $envelope->requestId);
        self::assertSame('trace-xyz', $envelope->traceId);
        self::assertSame('span-123', $envelope->spanId);
        self::assertSame('job-456', $envelope->jobId);
    }

    #[Test]
    public function envelopeDefaultsToEmptyCorrelationContext(): void
    {
        $factory = new EventFactory('staging', 'host2', new Randomizer(new Mt19937(42)));

        $envelope = $factory->envelope($this->createEvent());

        self::assertNull($envelope->requestId);
        self::assertNull($envelope->traceId);
    }

    #[Test]
    public function envelopeComputesPayloadHash(): void
    {
        $factory = new EventFactory('local', 'host', new Randomizer(new Mt19937(42)));
        $event = $this->createEvent();

        $envelope = $factory->envelope($event);

        self::assertNotEmpty($envelope->payloadHash);
        self::assertSame(64, strlen($envelope->payloadHash));
    }

    #[Test]
    public function createFactoryAutoDetectsHostname(): void
    {
        $factory = EventFactory::create('local', new Randomizer(new Mt19937(42)));

        $envelope = $factory->envelope($this->createEvent());

        self::assertNotEmpty($envelope->hostname);
    }

    private function createEvent(): ConsoleEvent
    {
        return new class implements ConsoleEvent {
            #[Override]
            public function eventType(): EventType
            {
                return EventType::LogEntry;
            }

            #[Override]
            public function schemaVersion(): EventVersion
            {
                return EventVersion::V1;
            }

            #[Override]
            public function toArray(): array
            {
                return ['level' => 'info', 'message' => 'test'];
            }
        };
    }
}
