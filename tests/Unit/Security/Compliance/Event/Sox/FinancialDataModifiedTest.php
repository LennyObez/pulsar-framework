<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Sox;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Sox\FinancialDataModified;

#[CoversClass(FinancialDataModified::class)]
final class FinancialDataModifiedTest extends TestCase
{
    private function createEvent(): FinancialDataModified
    {
        return new FinancialDataModified(
            eventId: 'evt-sox-001',
            occurredAt: new DateTimeImmutable('2026-03-05T14:00:00+00:00'),
            correlationId: 'corr-sox-1',
            nonce: 'nonce-sox-1',
            modifierIdentity: 'cfo@company.com',
            entityType: 'general_ledger_entry',
            entityId: 'gl-2026-00451',
            fieldSnapshots: [
                'amount' => ['old' => 10000, 'new' => 15000],
                'account' => ['old' => '4100', 'new' => '4200'],
            ],
            reason: 'quarterly_adjustment',
        );
    }

    #[Test]
    public function regulationReturnsSox(): void
    {
        self::assertSame('sox', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsFinancialDataModified(): void
    {
        self::assertSame('financial_data_modified', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-sox-001', $array['event_id']);
        self::assertSame('cfo@company.com', $array['modifier_identity']);
        self::assertSame('general_ledger_entry', $array['entity_type']);
        self::assertSame('gl-2026-00451', $array['entity_id']);
        self::assertIsArray($array['field_snapshots']);
        self::assertArrayHasKey('amount', $array['field_snapshots']);
        self::assertSame('quarterly_adjustment', $array['reason']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = FinancialDataModified::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->modifierIdentity, $restored->modifierIdentity);
        self::assertSame($original->entityType, $restored->entityType);
        self::assertSame($original->entityId, $restored->entityId);
        self::assertSame($original->fieldSnapshots, $restored->fieldSnapshots);
        self::assertSame($original->reason, $restored->reason);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = FinancialDataModified::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->modifierIdentity);
        self::assertSame('', $event->entityType);
        self::assertSame('', $event->entityId);
        self::assertSame([], $event->fieldSnapshots);
        self::assertSame('', $event->reason);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, FinancialDataModified::SCHEMA_VERSION);
    }
}
