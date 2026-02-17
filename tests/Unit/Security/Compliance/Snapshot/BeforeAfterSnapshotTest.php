<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Snapshot;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\Compliance\Snapshot\BeforeAfterSnapshot;
use Pulsar\Security\Compliance\Snapshot\ClassifiedField;
use Pulsar\Security\Compliance\Snapshot\Snapshot;

#[CoversClass(BeforeAfterSnapshot::class)]
final class BeforeAfterSnapshotTest extends TestCase
{
    #[Test]
    public function constructsWithBeforeAndAfter(): void
    {
        $before = $this->createSnapshot('before-value');
        $after = $this->createSnapshot('after-value');

        $diff = new BeforeAfterSnapshot(
            before: $before,
            after: $after,
        );

        self::assertSame($before, $diff->before);
        self::assertSame($after, $diff->after);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $before = $this->createSnapshot('old-status');
        $after = $this->createSnapshot('new-status');

        $diff = new BeforeAfterSnapshot(
            before: $before,
            after: $after,
        );

        $array = $diff->toArray();

        self::assertArrayHasKey('before', $array);
        self::assertArrayHasKey('after', $array);
        self::assertCount(2, $array);
        self::assertSame($before->toArray(), $array['before']);
        self::assertSame($after->toArray(), $array['after']);
    }

    #[Test]
    public function fromArrayCreatesEquivalentSnapshot(): void
    {
        $original = new BeforeAfterSnapshot(
            before: $this->createSnapshot('draft'),
            after: $this->createSnapshot('published'),
        );

        /** @var array{before: array{entity_type: string, entity_id: string, fields: list<array{name: string, value: mixed, classification: string}>, captured_at: string}, after: array{entity_type: string, entity_id: string, fields: list<array{name: string, value: mixed, classification: string}>, captured_at: string}} $data */
        $data = $original->toArray();
        $reconstructed = BeforeAfterSnapshot::fromArray($data);

        self::assertSame($original->before->entityType, $reconstructed->before->entityType);
        self::assertSame($original->before->entityId, $reconstructed->before->entityId);
        self::assertSame($original->after->entityType, $reconstructed->after->entityType);
        self::assertSame($original->after->entityId, $reconstructed->after->entityId);
    }

    #[Test]
    public function fromArrayRoundtripPreservesData(): void
    {
        $original = new BeforeAfterSnapshot(
            before: $this->createSnapshot('active'),
            after: $this->createSnapshot('suspended'),
        );

        /** @var array{before: array{entity_type: string, entity_id: string, fields: list<array{name: string, value: mixed, classification: string}>, captured_at: string}, after: array{entity_type: string, entity_id: string, fields: list<array{name: string, value: mixed, classification: string}>, captured_at: string}} $data */
        $data = $original->toArray();
        $reconstructed = BeforeAfterSnapshot::fromArray($data);

        self::assertSame($original->toArray(), $reconstructed->toArray());
    }

    private function createSnapshot(string $fieldValue): Snapshot
    {
        return new Snapshot(
            entityType: 'Account',
            entityId: 'acc-1',
            fields: [
                new ClassifiedField('status', $fieldValue, DataClassification::Internal),
            ],
            capturedAt: new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00'),
        );
    }
}
