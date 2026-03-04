<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Hipaa\PhiModified;

#[CoversClass(PhiModified::class)]
final class PhiModifiedTest extends TestCase
{
    private function createEvent(): PhiModified
    {
        return new PhiModified(
            eventId: 'evt-phi-m-001',
            occurredAt: new DateTimeImmutable('2026-03-01T09:00:00+00:00'),
            correlationId: 'corr-300',
            nonce: 'nonce-003',
            modifierIdentity: 'admin@hospital.org',
            patientPseudonym: 'patient-def',
            phiCategories: ['demographics', 'contact_info'],
            modificationType: 'update',
            reason: 'patient_request',
        );
    }

    #[Test]
    public function regulationReturnsHipaa(): void
    {
        self::assertSame('hipaa', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsPhiModified(): void
    {
        self::assertSame('phi_modified', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-phi-m-001', $array['event_id']);
        self::assertSame('admin@hospital.org', $array['modifier_identity']);
        self::assertSame('patient-def', $array['patient_pseudonym']);
        self::assertSame(['demographics', 'contact_info'], $array['phi_categories']);
        self::assertSame('update', $array['modification_type']);
        self::assertSame('patient_request', $array['reason']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = PhiModified::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->modifierIdentity, $restored->modifierIdentity);
        self::assertSame($original->patientPseudonym, $restored->patientPseudonym);
        self::assertSame($original->phiCategories, $restored->phiCategories);
        self::assertSame($original->modificationType, $restored->modificationType);
        self::assertSame($original->reason, $restored->reason);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = PhiModified::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->modifierIdentity);
        self::assertSame('', $event->patientPseudonym);
        self::assertSame([], $event->phiCategories);
        self::assertSame('', $event->modificationType);
        self::assertSame('', $event->reason);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, PhiModified::SCHEMA_VERSION);
    }
}
