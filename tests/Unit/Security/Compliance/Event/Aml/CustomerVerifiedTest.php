<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Aml\CustomerVerified;

#[CoversClass(CustomerVerified::class)]
final class CustomerVerifiedTest extends TestCase
{
    private function createEvent(): CustomerVerified
    {
        return new CustomerVerified(
            eventId: 'evt-aml-cv-001',
            occurredAt: new DateTimeImmutable('2026-03-10T10:00:00+00:00'),
            correlationId: 'corr-aml-cv-1',
            nonce: 'nonce-aml-cv-1',
            verifierIdentity: 'kyc-engine-v2',
            customerPseudonym: 'cust-anon-99',
            verificationType: 'enhanced_due_diligence',
            verificationLevel: 'high',
            documentTypes: ['passport', 'utility_bill'],
        );
    }

    #[Test]
    public function regulationReturnsAml(): void
    {
        self::assertSame('aml', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsCustomerVerified(): void
    {
        self::assertSame('customer_verified', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-aml-cv-001', $array['event_id']);
        self::assertSame('kyc-engine-v2', $array['verifier_identity']);
        self::assertSame('cust-anon-99', $array['customer_pseudonym']);
        self::assertSame('enhanced_due_diligence', $array['verification_type']);
        self::assertSame('high', $array['verification_level']);
        self::assertSame(['passport', 'utility_bill'], $array['document_types']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = CustomerVerified::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->verifierIdentity, $restored->verifierIdentity);
        self::assertSame($original->customerPseudonym, $restored->customerPseudonym);
        self::assertSame($original->verificationType, $restored->verificationType);
        self::assertSame($original->verificationLevel, $restored->verificationLevel);
        self::assertSame($original->documentTypes, $restored->documentTypes);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = CustomerVerified::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->verifierIdentity);
        self::assertSame('', $event->customerPseudonym);
        self::assertSame('', $event->verificationType);
        self::assertSame('', $event->verificationLevel);
        self::assertSame([], $event->documentTypes);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, CustomerVerified::SCHEMA_VERSION);
    }
}
