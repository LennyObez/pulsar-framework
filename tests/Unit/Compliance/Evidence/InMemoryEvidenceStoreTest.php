<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Evidence\EvidenceChainHead;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Compliance\Evidence\InMemoryEvidenceStore;

#[CoversClass(InMemoryEvidenceStore::class)]
final class InMemoryEvidenceStoreTest extends TestCase
{
    #[Test]
    public function storeAndGet(): void
    {
        $store = new InMemoryEvidenceStore();
        $record = $this->createRecord('r1', 'C-1');

        $store->store($record);

        self::assertSame($record, $store->get('r1'));
    }

    #[Test]
    public function theAnchorIsKeptSoTheChainBehavesTheSameWayItDoesOverAFile(): void
    {
        // Records and anchor live in the same object and are written in the same
        // call, so they cannot disagree. What this store cannot do is survive the
        // process, and that is said on chainState() rather than dressed up as an
        // anchoring problem — a chain over it would otherwise report Unanchored on
        // every test and every development run.
        $store = new InMemoryEvidenceStore();

        self::assertFalse($store->hasHead());
        self::assertNull($store->head());

        $head = new EvidenceChainHead(
            version: 1,
            genesis: 'genesis-commitment',
            height: 2,
            signature: 'tail-signature',
            updatedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            mac: 'mac',
        );

        $store->writeHead($head);

        self::assertTrue($store->hasHead());
        self::assertSame($head, $store->head());
    }

    #[Test]
    public function getReturnsNullForMissing(): void
    {
        $store = new InMemoryEvidenceStore();

        self::assertNull($store->get('nonexistent'));
    }

    #[Test]
    public function forControlFiltersCorrectly(): void
    {
        $store = new InMemoryEvidenceStore();
        $store->store($this->createRecord('r1', 'C-1'));
        $store->store($this->createRecord('r2', 'C-1'));
        $store->store($this->createRecord('r3', 'C-2'));

        $results = $store->forControl('C-1');

        self::assertCount(2, $results);
    }

    #[Test]
    public function allReturnsEverything(): void
    {
        $store = new InMemoryEvidenceStore();
        $store->store($this->createRecord('r1', 'C-1'));
        $store->store($this->createRecord('r2', 'C-2'));

        self::assertCount(2, $store->all());
    }

    #[Test]
    public function countForControl(): void
    {
        $store = new InMemoryEvidenceStore();
        $store->store($this->createRecord('r1', 'C-1'));
        $store->store($this->createRecord('r2', 'C-1'));
        $store->store($this->createRecord('r3', 'C-2'));

        self::assertSame(2, $store->countForControl('C-1'));
        self::assertSame(1, $store->countForControl('C-2'));
        self::assertSame(0, $store->countForControl('C-3'));
    }

    private function createRecord(string $id, string $controlId): EvidenceRecord
    {
        return new EvidenceRecord(
            id: $id,
            controlId: $controlId,
            type: 'test',
            description: 'Test record',
            data: [],
            collectedAt: new DateTimeImmutable(),
        );
    }
}
