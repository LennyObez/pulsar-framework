<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Dora\ThirdPartyRiskAssessed;

#[CoversClass(ThirdPartyRiskAssessed::class)]
final class ThirdPartyRiskAssessedTest extends TestCase
{
    private function createEvent(): ThirdPartyRiskAssessed
    {
        return new ThirdPartyRiskAssessed(
            eventId: 'evt-dora-tpr-001',
            occurredAt: new DateTimeImmutable('2026-03-01T10:00:00+00:00'),
            correlationId: 'corr-dora-2',
            nonce: 'nonce-dora-2',
            assessorIdentity: 'risk-officer@bank.eu',
            providerName: 'CloudProvider Inc.',
            riskLevel: 'medium',
            findings: ['no_incident_response_plan', 'weak_encryption_at_rest'],
            nextReviewDate: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
        );
    }

    #[Test]
    public function regulationReturnsDora(): void
    {
        self::assertSame('dora', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsThirdPartyRiskAssessed(): void
    {
        self::assertSame('third_party_risk_assessed', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-dora-tpr-001', $array['event_id']);
        self::assertSame('risk-officer@bank.eu', $array['assessor_identity']);
        self::assertSame('CloudProvider Inc.', $array['provider_name']);
        self::assertSame('medium', $array['risk_level']);
        self::assertSame(['no_incident_response_plan', 'weak_encryption_at_rest'], $array['findings']);
        self::assertIsString($array['next_review_date']);
        self::assertStringContainsString('2026-09-01', $array['next_review_date']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = ThirdPartyRiskAssessed::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->assessorIdentity, $restored->assessorIdentity);
        self::assertSame($original->providerName, $restored->providerName);
        self::assertSame($original->riskLevel, $restored->riskLevel);
        self::assertSame($original->findings, $restored->findings);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = ThirdPartyRiskAssessed::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->assessorIdentity);
        self::assertSame('', $event->providerName);
        self::assertSame('', $event->riskLevel);
        self::assertSame([], $event->findings);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, ThirdPartyRiskAssessed::SCHEMA_VERSION);
    }
}
