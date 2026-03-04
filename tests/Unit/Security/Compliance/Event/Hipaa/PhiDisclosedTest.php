<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Hipaa\PhiDisclosed;

#[CoversClass(PhiDisclosed::class)]
final class PhiDisclosedTest extends TestCase
{
    private function createEvent(): PhiDisclosed
    {
        return new PhiDisclosed(
            eventId: 'evt-phi-d-001',
            occurredAt: new DateTimeImmutable('2026-02-10T14:00:00+00:00'),
            correlationId: 'corr-200',
            nonce: 'nonce-002',
            discloserIdentity: 'dr.smith@hospital.org',
            recipientIdentity: 'insurance-co',
            patientPseudonym: 'patient-xyz',
            phiCategories: ['diagnosis', 'treatment_plan'],
            legalBasis: 'tpo_payment',
        );
    }

    #[Test]
    public function regulationReturnsHipaa(): void
    {
        self::assertSame('hipaa', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsPhiDisclosed(): void
    {
        self::assertSame('phi_disclosed', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-phi-d-001', $array['event_id']);
        self::assertSame('dr.smith@hospital.org', $array['discloser_identity']);
        self::assertSame('insurance-co', $array['recipient_identity']);
        self::assertSame('patient-xyz', $array['patient_pseudonym']);
        self::assertSame(['diagnosis', 'treatment_plan'], $array['phi_categories']);
        self::assertSame('tpo_payment', $array['legal_basis']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = PhiDisclosed::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->discloserIdentity, $restored->discloserIdentity);
        self::assertSame($original->recipientIdentity, $restored->recipientIdentity);
        self::assertSame($original->patientPseudonym, $restored->patientPseudonym);
        self::assertSame($original->phiCategories, $restored->phiCategories);
        self::assertSame($original->legalBasis, $restored->legalBasis);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = PhiDisclosed::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->discloserIdentity);
        self::assertSame('', $event->recipientIdentity);
        self::assertSame('', $event->patientPseudonym);
        self::assertSame([], $event->phiCategories);
        self::assertSame('', $event->legalBasis);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, PhiDisclosed::SCHEMA_VERSION);
    }
}
