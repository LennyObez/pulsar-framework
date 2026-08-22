<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Sox;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Sox\AccessControlChanged;

#[CoversClass(AccessControlChanged::class)]
final class AccessControlChangedTest extends TestCase
{
    private function createEvent(): AccessControlChanged
    {
        return new AccessControlChanged(
            eventId: 'evt-sox-ac-001',
            occurredAt: new DateTimeImmutable('2026-03-05T18:00:00+00:00'),
            correlationId: 'corr-sox-ac-1',
            nonce: 'nonce-sox-ac-1',
            operatorIdentity: 'admin@company.com',
            targetIdentity: 'analyst@company.com',
            changeType: 'grant',
            permission: 'financial_report_approve',
            justification: 'Role promotion to senior analyst',
        );
    }

    #[Test]
    public function regulationReturnsSox(): void
    {
        self::assertSame('sox', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsAccessControlChanged(): void
    {
        self::assertSame('access_control_changed', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-sox-ac-001', $array['event_id']);
        self::assertSame('admin@company.com', $array['operator_identity']);
        self::assertSame('analyst@company.com', $array['target_identity']);
        self::assertSame('grant', $array['change_type']);
        self::assertSame('financial_report_approve', $array['permission']);
        self::assertSame('Role promotion to senior analyst', $array['justification']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = AccessControlChanged::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->operatorIdentity, $restored->operatorIdentity);
        self::assertSame($original->targetIdentity, $restored->targetIdentity);
        self::assertSame($original->changeType, $restored->changeType);
        self::assertSame($original->permission, $restored->permission);
        self::assertSame($original->justification, $restored->justification);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = AccessControlChanged::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->operatorIdentity);
        self::assertSame('', $event->targetIdentity);
        self::assertSame('', $event->changeType);
        self::assertSame('', $event->permission);
        self::assertSame('', $event->justification);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, AccessControlChanged::SCHEMA_VERSION);
    }
}
