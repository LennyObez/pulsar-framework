<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Snapshot;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\Compliance\Snapshot\ClassifiedField;
use Pulsar\Security\Compliance\Snapshot\Snapshot;

#[CoversClass(Snapshot::class)]
final class SnapshotTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $capturedAt = new DateTimeImmutable('2025-06-15T10:30:00+00:00');
        $fields = [
            new ClassifiedField('name', 'John Doe', DataClassification::Confidential),
            new ClassifiedField('role', 'admin', DataClassification::Internal),
        ];

        $snapshot = new Snapshot(
            entityType: 'User',
            entityId: 'user-42',
            fields: $fields,
            capturedAt: $capturedAt,
        );

        self::assertSame('User', $snapshot->entityType);
        self::assertSame('user-42', $snapshot->entityId);
        self::assertCount(2, $snapshot->fields);
        self::assertSame($capturedAt, $snapshot->capturedAt);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $capturedAt = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');
        $fields = [
            new ClassifiedField('account', 'ACC-001', DataClassification::Confidential),
        ];

        $snapshot = new Snapshot(
            entityType: 'Account',
            entityId: 'acc-1',
            fields: $fields,
            capturedAt: $capturedAt,
        );

        $array = $snapshot->toArray();

        self::assertSame('Account', $array['entity_type']);
        self::assertSame('acc-1', $array['entity_id']);
        self::assertSame('2025-06-15T10:30:00.000000+00:00', $array['captured_at']);

        /** @var list<array{name: string, value: mixed, classification: string}> $fields */
        $fields = $array['fields'];
        self::assertCount(1, $fields);
        self::assertSame('account', $fields[0]['name']);
        self::assertSame('ACC-001', $fields[0]['value']);
        self::assertSame('confidential', $fields[0]['classification']);
    }

    #[Test]
    public function toArrayContainsAllKeys(): void
    {
        $snapshot = new Snapshot(
            entityType: 'Entity',
            entityId: 'e-1',
            fields: [],
            capturedAt: new DateTimeImmutable(),
        );

        $array = $snapshot->toArray();

        self::assertArrayHasKey('entity_type', $array);
        self::assertArrayHasKey('entity_id', $array);
        self::assertArrayHasKey('fields', $array);
        self::assertArrayHasKey('captured_at', $array);
        self::assertCount(4, $array);
    }

    #[Test]
    public function fromArrayCreatesEquivalentSnapshot(): void
    {
        $original = new Snapshot(
            entityType: 'Transaction',
            entityId: 'txn-99',
            fields: [
                new ClassifiedField('amount', 1500, DataClassification::Confidential),
                new ClassifiedField('memo', 'Payment received', DataClassification::Internal),
            ],
            capturedAt: new DateTimeImmutable('2025-03-20T14:00:00.000000+00:00'),
        );

        /** @var array{entity_type: string, entity_id: string, fields: list<array{name: string, value: mixed, classification: string}>, captured_at: string} $data */
        $data = $original->toArray();
        $reconstructed = Snapshot::fromArray($data);

        self::assertSame($original->entityType, $reconstructed->entityType);
        self::assertSame($original->entityId, $reconstructed->entityId);
        self::assertCount(2, $reconstructed->fields);
        self::assertSame('amount', $reconstructed->fields[0]->name);
        self::assertSame(1500, $reconstructed->fields[0]->value);
        self::assertSame(DataClassification::Confidential, $reconstructed->fields[0]->classification);
        self::assertSame('memo', $reconstructed->fields[1]->name);
        self::assertSame('Payment received', $reconstructed->fields[1]->value);
        self::assertSame(DataClassification::Internal, $reconstructed->fields[1]->classification);
    }

    #[Test]
    public function fromArrayRoundtripPreservesData(): void
    {
        $original = new Snapshot(
            entityType: 'Invoice',
            entityId: 'inv-007',
            fields: [
                new ClassifiedField('total', 250.50, DataClassification::Confidential),
                new ClassifiedField('status', 'paid', DataClassification::Internal),
                new ClassifiedField('ssn', '[REDACTED]', DataClassification::Restricted),
            ],
            capturedAt: new DateTimeImmutable('2025-01-10T08:00:00.000000+00:00'),
        );

        /** @var array{entity_type: string, entity_id: string, fields: list<array{name: string, value: mixed, classification: string}>, captured_at: string} $data */
        $data = $original->toArray();
        $reconstructed = Snapshot::fromArray($data);

        self::assertSame($original->toArray(), $reconstructed->toArray());
    }

    #[Test]
    public function fromArrayWithVariousClassifications(): void
    {
        $data = [
            'entity_type' => 'User',
            'entity_id' => 'u-1',
            'fields' => [
                ['name' => 'email', 'value' => 'a@b.com', 'classification' => 'internal'],
                ['name' => 'ssn', 'value' => '[REDACTED]', 'classification' => 'restricted'],
                ['name' => 'balance', 'value' => 100, 'classification' => 'confidential'],
            ],
            'captured_at' => '2025-06-01T12:00:00.000000+00:00',
        ];

        $snapshot = Snapshot::fromArray($data);

        self::assertSame(DataClassification::Internal, $snapshot->fields[0]->classification);
        self::assertSame(DataClassification::Restricted, $snapshot->fields[1]->classification);
        self::assertSame(DataClassification::Confidential, $snapshot->fields[2]->classification);
    }
}
