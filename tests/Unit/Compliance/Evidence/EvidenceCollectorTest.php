<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Evidence\EvidenceCollector;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Compliance\Evidence\InMemoryEvidenceStore;

#[CoversClass(EvidenceCollector::class)]
final class EvidenceCollectorTest extends TestCase
{
    #[Test]
    public function collectCreatesAndStoresRecord(): void
    {
        $store = new InMemoryEvidenceStore();
        $collector = new EvidenceCollector($store);

        $record = $collector->collect(
            controlId: 'SOC2-CC6.1',
            type: 'access_control',
            description: 'RBAC configured',
            data: ['roles' => 5],
        );

        self::assertSame('SOC2-CC6.1', $record->controlId);
        self::assertSame('access_control', $record->type);
        self::assertSame('RBAC configured', $record->description);
        self::assertNotEmpty($record->id);

        // Verify stored
        self::assertNotNull($store->get($record->id));
    }

    #[Test]
    public function collectWithSignature(): void
    {
        $store = new InMemoryEvidenceStore();
        $collector = new EvidenceCollector($store);

        $record = $collector->collect('C-1', 'test', 'Signed', signature: 'abc123');

        self::assertSame('abc123', $record->signature);
    }

    #[Test]
    public function registerAndRunCollector(): void
    {
        $store = new InMemoryEvidenceStore();
        $collector = new EvidenceCollector($store);

        $collector->registerCollector('config', static function (string $controlId): array {
            return [
                new EvidenceRecord(
                    id: 'auto-1',
                    controlId: $controlId,
                    type: 'configuration',
                    description: 'Auto-collected config',
                    data: ['enabled' => true],
                    collectedAt: new DateTimeImmutable(),
                ),
            ];
        });

        $results = $collector->collectByType('TEST-1', 'config');

        self::assertCount(1, $results);
        self::assertSame('TEST-1', $results[0]->controlId);
        self::assertNotNull($store->get('auto-1'));
    }

    #[Test]
    public function collectAllRunsAllCollectors(): void
    {
        $store = new InMemoryEvidenceStore();
        $collector = new EvidenceCollector($store);

        $collector->registerCollector('type_a', static fn(string $id): array => [
            new EvidenceRecord('a1', $id, 'type_a', 'A', [], new DateTimeImmutable()),
        ]);

        $collector->registerCollector('type_b', static fn(string $id): array => [
            new EvidenceRecord('b1', $id, 'type_b', 'B', [], new DateTimeImmutable()),
        ]);

        $results = $collector->collectAll('C-1');

        self::assertCount(2, $results);
        self::assertCount(2, $store->all());
    }

    #[Test]
    public function collectByTypeReturnsEmptyForUnknownType(): void
    {
        $store = new InMemoryEvidenceStore();
        $collector = new EvidenceCollector($store);

        self::assertCount(0, $collector->collectByType('C-1', 'unknown'));
    }

    #[Test]
    public function storeAccessor(): void
    {
        $store = new InMemoryEvidenceStore();
        $collector = new EvidenceCollector($store);

        self::assertSame($store, $collector->store());
    }
}
