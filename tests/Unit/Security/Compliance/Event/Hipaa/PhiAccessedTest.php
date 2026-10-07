<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Hipaa\PhiAccessed;

#[CoversClass(PhiAccessed::class)]
final class PhiAccessedTest extends TestCase
{
    private function createEvent(): PhiAccessed
    {
        return new PhiAccessed(
            eventId: 'evt-phi-001',
            occurredAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            correlationId: 'corr-100',
            nonce: 'nonce-001',
            accessorIdentity: 'nurse@hospital.org',
            patientPseudonym: 'patient-abc',
            phiCategories: ['medical_records', 'lab_results'],
            purpose: 'treatment',
            accessMethod: 'ehr_portal',
        );
    }

    #[Test]
    public function regulationReturnsHipaa(): void
    {
        self::assertSame('hipaa', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsPhiAccessed(): void
    {
        self::assertSame('phi_accessed', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-phi-001', $array['event_id']);
        self::assertSame('corr-100', $array['correlation_id']);
        self::assertSame('nurse@hospital.org', $array['accessor_identity']);
        self::assertSame('patient-abc', $array['patient_pseudonym']);
        self::assertSame(['medical_records', 'lab_results'], $array['phi_categories']);
        self::assertSame('treatment', $array['purpose']);
        self::assertSame('ehr_portal', $array['access_method']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = PhiAccessed::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->accessorIdentity, $restored->accessorIdentity);
        self::assertSame($original->patientPseudonym, $restored->patientPseudonym);
        self::assertSame($original->phiCategories, $restored->phiCategories);
        self::assertSame($original->purpose, $restored->purpose);
        self::assertSame($original->accessMethod, $restored->accessMethod);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = PhiAccessed::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->accessorIdentity);
        self::assertSame('', $event->patientPseudonym);
        self::assertSame([], $event->phiCategories);
        self::assertSame('', $event->purpose);
        self::assertSame('', $event->accessMethod);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, PhiAccessed::SCHEMA_VERSION);
    }
}
