<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Aml\SuspiciousActivityDetected;

#[CoversClass(SuspiciousActivityDetected::class)]
final class SuspiciousActivityDetectedTest extends TestCase
{
    private function createEvent(): SuspiciousActivityDetected
    {
        return new SuspiciousActivityDetected(
            eventId: 'evt-aml-sad-001',
            occurredAt: new DateTimeImmutable('2026-03-10T12:00:00+00:00'),
            correlationId: 'corr-aml-sad-1',
            nonce: 'nonce-aml-sad-1',
            detectorIdentity: 'fraud-detection-v5',
            customerPseudonym: 'cust-anon-55',
            activityType: 'structuring',
            riskScore: 0.92,
            description: 'Multiple sub-threshold deposits within 24h window',
        );
    }

    #[Test]
    public function regulationReturnsAml(): void
    {
        self::assertSame('aml', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsSuspiciousActivityDetected(): void
    {
        self::assertSame('suspicious_activity_detected', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-aml-sad-001', $array['event_id']);
        self::assertSame('fraud-detection-v5', $array['detector_identity']);
        self::assertSame('cust-anon-55', $array['customer_pseudonym']);
        self::assertSame('structuring', $array['activity_type']);
        self::assertSame(0.92, $array['risk_score']);
        self::assertSame('Multiple sub-threshold deposits within 24h window', $array['description']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = SuspiciousActivityDetected::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->detectorIdentity, $restored->detectorIdentity);
        self::assertSame($original->customerPseudonym, $restored->customerPseudonym);
        self::assertSame($original->activityType, $restored->activityType);
        self::assertSame($original->riskScore, $restored->riskScore);
        self::assertSame($original->description, $restored->description);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = SuspiciousActivityDetected::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->detectorIdentity);
        self::assertSame('', $event->customerPseudonym);
        self::assertSame('', $event->activityType);
        self::assertSame(0.0, $event->riskScore);
        self::assertSame('', $event->description);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, SuspiciousActivityDetected::SCHEMA_VERSION);
    }

    #[Test]
    public function fromArrayHandlesIntegerRiskScore(): void
    {
        $event = SuspiciousActivityDetected::fromArray([
            'event_id' => 'evt-1',
            'risk_score' => 1,
        ]);

        self::assertSame(1.0, $event->riskScore);
    }
}
