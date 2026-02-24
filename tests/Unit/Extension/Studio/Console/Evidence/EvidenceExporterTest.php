<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceArchive;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceExporter;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Exception\StudioException;
use Pulsar\Security\Crypto\HmacInterface;

#[CoversClass(EvidenceExporter::class)]
final class EvidenceExporterTest extends TestCase
{
    #[Test]
    public function exportReturnsArchiveWithEvents(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([
            ['event_id' => 'e1', 'type' => 'http.request'],
        ]);

        $exporter = new EvidenceExporter($store);
        $archive = $exporter->export();

        self::assertInstanceOf(EvidenceArchive::class, $archive);
        self::assertCount(1, $archive->events);
        self::assertSame('e1', $archive->events[0]['event_id']);
        self::assertSame(1, $archive->manifest['version']);
        self::assertSame(1, $archive->manifest['event_count']);
        self::assertNull($archive->mac);
    }

    #[Test]
    public function exportWithMacKeyComputesMac(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('computeHex')->willReturn('mac-hex-value');

        $exporter = new EvidenceExporter(
            store: $store,
            hmac: $hmac,
            archiveMacKey: 'test-key',
        );

        $archive = $exporter->export();

        self::assertSame('mac-hex-value', $archive->mac);
    }

    #[Test]
    public function exportWithoutMacKeyProducesNullMac(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $exporter = new EvidenceExporter($store, archiveMacKey: null);
        $archive = $exporter->export();

        self::assertNull($archive->mac);
    }

    #[Test]
    public function exportThrowsWhenEncryptedWithoutDecryptionKey(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $exporter = new EvidenceExporter(
            store: $store,
            isEncrypted: true,
            hasDecryptionKey: false,
        );

        $this->expectException(StudioException::class);
        $exporter->export();
    }

    #[Test]
    public function exportAllowsEncryptedWithDecryptionKey(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $exporter = new EvidenceExporter(
            store: $store,
            isEncrypted: true,
            hasDecryptionKey: true,
        );

        $archive = $exporter->export();

        self::assertInstanceOf(EvidenceArchive::class, $archive);
    }

    #[Test]
    public function exportPassesFiltersToQuery(): void
    {
        $filters = ['type' => 'http.request'];

        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with($filters, limit: PHP_INT_MAX)
            ->willReturn([]);

        $exporter = new EvidenceExporter($store);
        $archive = $exporter->export($filters);

        self::assertSame(0, $archive->manifest['event_count']);
        self::assertSame($filters, $archive->manifest['filters']);
    }

    #[Test]
    public function exportManifestContainsExpectedFields(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([
            ['id' => '1'], ['id' => '2'], ['id' => '3'],
        ]);

        $exporter = new EvidenceExporter($store);
        $archive = $exporter->export();

        self::assertArrayHasKey('version', $archive->manifest);
        self::assertArrayHasKey('exported_at', $archive->manifest);
        self::assertArrayHasKey('event_count', $archive->manifest);
        self::assertArrayHasKey('chain_link_count', $archive->manifest);
        self::assertSame(3, $archive->manifest['event_count']);
    }
}
