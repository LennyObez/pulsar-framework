<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Sox;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Sox\AuditTrailVerified;

#[CoversClass(AuditTrailVerified::class)]
final class AuditTrailVerifiedTest extends TestCase
{
    private function createEvent(): AuditTrailVerified
    {
        return new AuditTrailVerified(
            eventId: 'evt-sox-atv-001',
            occurredAt: new DateTimeImmutable('2026-03-05T16:00:00+00:00'),
            correlationId: 'corr-sox-atv-1',
            nonce: 'nonce-sox-atv-1',
            verifierIdentity: 'auditor@company.com',
            verificationPeriod: '2026-Q1',
            chainIntegrity: true,
            entriesVerified: 48572,
        );
    }

    #[Test]
    public function regulationReturnsSox(): void
    {
        self::assertSame('sox', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsAuditTrailVerified(): void
    {
        self::assertSame('audit_trail_verified', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-sox-atv-001', $array['event_id']);
        self::assertSame('auditor@company.com', $array['verifier_identity']);
        self::assertSame('2026-Q1', $array['verification_period']);
        self::assertTrue($array['chain_integrity']);
        self::assertSame(48572, $array['entries_verified']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = AuditTrailVerified::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->verifierIdentity, $restored->verifierIdentity);
        self::assertSame($original->verificationPeriod, $restored->verificationPeriod);
        self::assertSame($original->chainIntegrity, $restored->chainIntegrity);
        self::assertSame($original->entriesVerified, $restored->entriesVerified);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = AuditTrailVerified::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->verifierIdentity);
        self::assertSame('', $event->verificationPeriod);
        self::assertFalse($event->chainIntegrity);
        self::assertSame(0, $event->entriesVerified);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, AuditTrailVerified::SCHEMA_VERSION);
    }
}
