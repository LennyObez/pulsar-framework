<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Dora\RecoveryInitiated;

#[CoversClass(RecoveryInitiated::class)]
final class RecoveryInitiatedTest extends TestCase
{
    private function createEvent(): RecoveryInitiated
    {
        return new RecoveryInitiated(
            eventId: 'evt-dora-ri-001',
            occurredAt: new DateTimeImmutable('2026-03-10T08:00:00+00:00'),
            correlationId: 'corr-dora-ri-1',
            nonce: 'nonce-dora-ri-1',
            operatorIdentity: 'incident-commander@bank.eu',
            incidentId: 'INC-2026-0042',
            recoveryPlan: 'failover-to-secondary-dc',
            estimatedRecoveryTime: 'PT4H',
        );
    }

    #[Test]
    public function regulationReturnsDora(): void
    {
        self::assertSame('dora', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsRecoveryInitiated(): void
    {
        self::assertSame('recovery_initiated', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-dora-ri-001', $array['event_id']);
        self::assertSame('incident-commander@bank.eu', $array['operator_identity']);
        self::assertSame('INC-2026-0042', $array['incident_id']);
        self::assertSame('failover-to-secondary-dc', $array['recovery_plan']);
        self::assertSame('PT4H', $array['estimated_recovery_time']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = RecoveryInitiated::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->operatorIdentity, $restored->operatorIdentity);
        self::assertSame($original->incidentId, $restored->incidentId);
        self::assertSame($original->recoveryPlan, $restored->recoveryPlan);
        self::assertSame($original->estimatedRecoveryTime, $restored->estimatedRecoveryTime);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = RecoveryInitiated::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->operatorIdentity);
        self::assertSame('', $event->incidentId);
        self::assertSame('', $event->recoveryPlan);
        self::assertSame('', $event->estimatedRecoveryTime);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, RecoveryInitiated::SCHEMA_VERSION);
    }
}
