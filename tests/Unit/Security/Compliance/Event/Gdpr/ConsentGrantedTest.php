<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Gdpr\ConsentGranted;

#[CoversClass(ConsentGranted::class)]
final class ConsentGrantedTest extends TestCase
{
    private function createEvent(): ConsentGranted
    {
        return new ConsentGranted(
            eventId: 'evt-gdpr-c-001',
            occurredAt: new DateTimeImmutable('2026-01-10T12:00:00+00:00'),
            correlationId: 'corr-gdpr-1',
            nonce: 'nonce-gdpr-1',
            subjectId: 'subject-abc',
            purpose: 'marketing_emails',
            legalBasis: 'consent',
            consentScope: 'email_campaigns',
            expiresAt: new DateTimeImmutable('2027-01-10T12:00:00+00:00'),
        );
    }

    #[Test]
    public function regulationReturnsGdpr(): void
    {
        self::assertSame('gdpr', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsConsentGranted(): void
    {
        self::assertSame('consent_granted', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-gdpr-c-001', $array['event_id']);
        self::assertSame('subject-abc', $array['subject_id']);
        self::assertSame('marketing_emails', $array['purpose']);
        self::assertSame('consent', $array['legal_basis']);
        self::assertSame('email_campaigns', $array['consent_scope']);
        self::assertIsString($array['expires_at']);
        self::assertStringContainsString('2027-01-10', $array['expires_at']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function toArrayWithNullExpiresAtOmitsValue(): void
    {
        $event = new ConsentGranted(
            eventId: 'evt-2',
            occurredAt: new DateTimeImmutable(),
            correlationId: 'corr-2',
            nonce: 'nonce-2',
            subjectId: 'subject-2',
            purpose: 'analytics',
            legalBasis: 'legitimate_interest',
            consentScope: 'usage_analytics',
            expiresAt: null,
        );

        $array = $event->toArray();

        self::assertNull($array['expires_at']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = ConsentGranted::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->subjectId, $restored->subjectId);
        self::assertSame($original->purpose, $restored->purpose);
        self::assertSame($original->legalBasis, $restored->legalBasis);
        self::assertSame($original->consentScope, $restored->consentScope);
        self::assertNotNull($restored->expiresAt);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = ConsentGranted::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->subjectId);
        self::assertSame('', $event->purpose);
        self::assertSame('', $event->legalBasis);
        self::assertSame('', $event->consentScope);
        self::assertNull($event->expiresAt);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, ConsentGranted::SCHEMA_VERSION);
    }
}
