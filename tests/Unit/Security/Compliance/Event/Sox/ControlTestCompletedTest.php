<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Sox;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Sox\ControlTestCompleted;

#[CoversClass(ControlTestCompleted::class)]
final class ControlTestCompletedTest extends TestCase
{
    private function createEvent(): ControlTestCompleted
    {
        return new ControlTestCompleted(
            eventId: 'evt-sox-ct-001',
            occurredAt: new DateTimeImmutable('2026-03-05T17:00:00+00:00'),
            correlationId: 'corr-sox-ct-1',
            nonce: 'nonce-sox-ct-1',
            testerIdentity: 'internal-audit@company.com',
            controlId: 'SOX-IT-GC-004',
            controlDescription: 'Segregation of duties for financial approvals',
            testResult: 'effective',
            findings: 'No exceptions noted',
        );
    }

    #[Test]
    public function regulationReturnsSox(): void
    {
        self::assertSame('sox', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsControlTestCompleted(): void
    {
        self::assertSame('control_test_completed', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-sox-ct-001', $array['event_id']);
        self::assertSame('internal-audit@company.com', $array['tester_identity']);
        self::assertSame('SOX-IT-GC-004', $array['control_id']);
        self::assertSame('Segregation of duties for financial approvals', $array['control_description']);
        self::assertSame('effective', $array['test_result']);
        self::assertSame('No exceptions noted', $array['findings']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = ControlTestCompleted::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->testerIdentity, $restored->testerIdentity);
        self::assertSame($original->controlId, $restored->controlId);
        self::assertSame($original->controlDescription, $restored->controlDescription);
        self::assertSame($original->testResult, $restored->testResult);
        self::assertSame($original->findings, $restored->findings);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = ControlTestCompleted::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->testerIdentity);
        self::assertSame('', $event->controlId);
        self::assertSame('', $event->controlDescription);
        self::assertSame('', $event->testResult);
        self::assertSame('', $event->findings);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, ControlTestCompleted::SCHEMA_VERSION);
    }
}
