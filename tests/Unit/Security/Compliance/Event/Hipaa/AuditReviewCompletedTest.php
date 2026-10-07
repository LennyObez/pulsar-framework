<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Hipaa\AuditReviewCompleted;

#[CoversClass(AuditReviewCompleted::class)]
final class AuditReviewCompletedTest extends TestCase
{
    private function createEvent(): AuditReviewCompleted
    {
        return new AuditReviewCompleted(
            eventId: 'evt-hipaa-ar-001',
            occurredAt: new DateTimeImmutable('2026-03-10T15:00:00+00:00'),
            correlationId: 'corr-hipaa-ar-1',
            nonce: 'nonce-hipaa-ar-1',
            reviewerIdentity: 'compliance@hospital.org',
            reviewPeriod: '2026-Q1',
            findingsCount: 12,
            criticalFindings: 2,
        );
    }

    #[Test]
    public function regulationReturnsHipaa(): void
    {
        self::assertSame('hipaa', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsAuditReviewCompleted(): void
    {
        self::assertSame('audit_review_completed', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-hipaa-ar-001', $array['event_id']);
        self::assertSame('compliance@hospital.org', $array['reviewer_identity']);
        self::assertSame('2026-Q1', $array['review_period']);
        self::assertSame(12, $array['findings_count']);
        self::assertSame(2, $array['critical_findings']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = AuditReviewCompleted::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->reviewerIdentity, $restored->reviewerIdentity);
        self::assertSame($original->reviewPeriod, $restored->reviewPeriod);
        self::assertSame($original->findingsCount, $restored->findingsCount);
        self::assertSame($original->criticalFindings, $restored->criticalFindings);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = AuditReviewCompleted::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->reviewerIdentity);
        self::assertSame('', $event->reviewPeriod);
        self::assertSame(0, $event->findingsCount);
        self::assertSame(0, $event->criticalFindings);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, AuditReviewCompleted::SCHEMA_VERSION);
    }
}
