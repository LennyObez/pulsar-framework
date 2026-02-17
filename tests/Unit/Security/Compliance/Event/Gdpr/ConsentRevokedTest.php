<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Gdpr\ConsentRevoked;

#[CoversClass(ConsentRevoked::class)]
final class ConsentRevokedTest extends TestCase
{
    private function createEvent(): ConsentRevoked
    {
        return new ConsentRevoked(
            eventId: 'evt-gdpr-cr-001',
            occurredAt: new DateTimeImmutable('2026-03-01T13:00:00+00:00'),
            correlationId: 'corr-gdpr-cr-1',
            nonce: 'nonce-gdpr-cr-1',
            subjectId: 'subject-revoke-1',
            purpose: 'marketing_emails',
            revocationReason: 'user_request',
        );
    }

    #[Test]
    public function regulationReturnsGdpr(): void
    {
        self::assertSame('gdpr', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsConsentRevoked(): void
    {
        self::assertSame('consent_revoked', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-gdpr-cr-001', $array['event_id']);
        self::assertSame('subject-revoke-1', $array['subject_id']);
        self::assertSame('marketing_emails', $array['purpose']);
        self::assertSame('user_request', $array['revocation_reason']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = ConsentRevoked::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->subjectId, $restored->subjectId);
        self::assertSame($original->purpose, $restored->purpose);
        self::assertSame($original->revocationReason, $restored->revocationReason);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = ConsentRevoked::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->subjectId);
        self::assertSame('', $event->purpose);
        self::assertSame('', $event->revocationReason);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, ConsentRevoked::SCHEMA_VERSION);
    }
}
