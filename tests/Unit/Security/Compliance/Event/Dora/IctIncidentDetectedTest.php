<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Dora\IctIncidentDetected;

#[CoversClass(IctIncidentDetected::class)]
final class IctIncidentDetectedTest extends TestCase
{
    private function createEvent(): IctIncidentDetected
    {
        return new IctIncidentDetected(
            eventId: 'evt-dora-001',
            occurredAt: new DateTimeImmutable('2026-02-20T08:00:00+00:00'),
            correlationId: 'corr-dora-1',
            nonce: 'nonce-dora-1',
            reporterIdentity: 'soc-team@bank.eu',
            incidentType: 'ransomware_detected',
            severity: 'major',
            affectedSystems: ['core_banking', 'payment_gateway'],
            description: 'Ransomware detected on core banking file share',
        );
    }

    #[Test]
    public function regulationReturnsDora(): void
    {
        self::assertSame('dora', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsIctIncidentDetected(): void
    {
        self::assertSame('ict_incident_detected', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-dora-001', $array['event_id']);
        self::assertSame('corr-dora-1', $array['correlation_id']);
        self::assertSame('soc-team@bank.eu', $array['reporter_identity']);
        self::assertSame('ransomware_detected', $array['incident_type']);
        self::assertSame('major', $array['severity']);
        self::assertSame(['core_banking', 'payment_gateway'], $array['affected_systems']);
        self::assertSame('Ransomware detected on core banking file share', $array['description']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = IctIncidentDetected::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->reporterIdentity, $restored->reporterIdentity);
        self::assertSame($original->incidentType, $restored->incidentType);
        self::assertSame($original->severity, $restored->severity);
        self::assertSame($original->affectedSystems, $restored->affectedSystems);
        self::assertSame($original->description, $restored->description);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = IctIncidentDetected::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->reporterIdentity);
        self::assertSame('', $event->incidentType);
        self::assertSame('', $event->severity);
        self::assertSame([], $event->affectedSystems);
        self::assertSame('', $event->description);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, IctIncidentDetected::SCHEMA_VERSION);
    }
}
