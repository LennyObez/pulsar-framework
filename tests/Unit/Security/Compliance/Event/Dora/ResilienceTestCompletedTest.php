<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Dora;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Dora\ResilienceTestCompleted;

#[CoversClass(ResilienceTestCompleted::class)]
final class ResilienceTestCompletedTest extends TestCase
{
    private function createEvent(): ResilienceTestCompleted
    {
        return new ResilienceTestCompleted(
            eventId: 'evt-dora-rt-001',
            occurredAt: new DateTimeImmutable('2026-03-10T09:00:00+00:00'),
            correlationId: 'corr-dora-rt-1',
            nonce: 'nonce-dora-rt-1',
            testerIdentity: 'resilience-team@bank.eu',
            testType: 'threat_led_penetration_testing',
            targetSystem: 'core-banking-platform',
            result: 'passed',
            recoveryTimeActual: 'PT2H15M',
        );
    }

    #[Test]
    public function regulationReturnsDora(): void
    {
        self::assertSame('dora', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsResilienceTestCompleted(): void
    {
        self::assertSame('resilience_test_completed', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-dora-rt-001', $array['event_id']);
        self::assertSame('resilience-team@bank.eu', $array['tester_identity']);
        self::assertSame('threat_led_penetration_testing', $array['test_type']);
        self::assertSame('core-banking-platform', $array['target_system']);
        self::assertSame('passed', $array['result']);
        self::assertSame('PT2H15M', $array['recovery_time_actual']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = ResilienceTestCompleted::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->testerIdentity, $restored->testerIdentity);
        self::assertSame($original->testType, $restored->testType);
        self::assertSame($original->targetSystem, $restored->targetSystem);
        self::assertSame($original->result, $restored->result);
        self::assertSame($original->recoveryTimeActual, $restored->recoveryTimeActual);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = ResilienceTestCompleted::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->testerIdentity);
        self::assertSame('', $event->testType);
        self::assertSame('', $event->targetSystem);
        self::assertSame('', $event->result);
        self::assertSame('', $event->recoveryTimeActual);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, ResilienceTestCompleted::SCHEMA_VERSION);
    }
}
