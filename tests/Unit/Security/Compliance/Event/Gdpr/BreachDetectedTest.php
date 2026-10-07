<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Gdpr\BreachDetected;

#[CoversClass(BreachDetected::class)]
final class BreachDetectedTest extends TestCase
{
    private function createEvent(): BreachDetected
    {
        return new BreachDetected(
            eventId: 'evt-gdpr-001',
            occurredAt: new DateTimeImmutable('2026-01-15T10:30:00+00:00'),
            correlationId: 'corr-123',
            nonce: 'nonce-abc',
            detectedBy: 'security-scanner',
            affectedSubjectCount: 1500,
            dataCategories: ['email', 'phone', 'address'],
            severity: 'high',
            description: 'Unauthorized access to customer database',
        );
    }

    #[Test]
    public function regulationReturnsGdpr(): void
    {
        self::assertSame('gdpr', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsBreachDetected(): void
    {
        self::assertSame('breach_detected', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $event = $this->createEvent();
        $array = $event->toArray();

        self::assertSame('evt-gdpr-001', $array['event_id']);
        self::assertSame('corr-123', $array['correlation_id']);
        self::assertSame('security-scanner', $array['detected_by']);
        self::assertSame(1500, $array['affected_subject_count']);
        self::assertSame(['email', 'phone', 'address'], $array['data_categories']);
        self::assertSame('high', $array['severity']);
        self::assertSame('Unauthorized access to customer database', $array['description']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = BreachDetected::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->detectedBy, $restored->detectedBy);
        self::assertSame($original->affectedSubjectCount, $restored->affectedSubjectCount);
        self::assertSame($original->dataCategories, $restored->dataCategories);
        self::assertSame($original->severity, $restored->severity);
        self::assertSame($original->description, $restored->description);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = BreachDetected::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->detectedBy);
        self::assertSame(0, $event->affectedSubjectCount);
        self::assertSame([], $event->dataCategories);
        self::assertSame('', $event->severity);
        self::assertSame('', $event->description);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, BreachDetected::SCHEMA_VERSION);
    }
}
