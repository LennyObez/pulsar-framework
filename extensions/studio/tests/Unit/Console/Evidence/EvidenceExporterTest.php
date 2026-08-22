<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceExporter;
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
            ['event_id' => 'e1', 'payload_json' => '{}'],
        ]);

        $exporter = new EvidenceExporter($store);
        $archive = $exporter->export();

        self::assertCount(1, $archive->events);
        self::assertSame('e1', $archive->events[0]['event_id']);
        self::assertSame(1, $archive->manifest['version']);
        self::assertSame(1, $archive->manifest['event_count']);
        self::assertNull($archive->mac);
    }

    #[Test]
    public function exportThrowsWhenEncryptedWithoutKey(): void
    {
        $store = $this->createStub(EventStoreInterface::class);

        $exporter = new EvidenceExporter(
            store: $store,
            isEncrypted: true,
            hasDecryptionKey: false,
        );

        $this->expectException(StudioException::class);
        $this->expectExceptionMessageIsOrContains('Cannot export');

        $exporter->export();
    }

    #[Test]
    public function exportSucceedsWhenEncryptedWithKey(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $exporter = new EvidenceExporter(
            store: $store,
            isEncrypted: true,
            hasDecryptionKey: true,
        );

        $archive = $exporter->export();

        self::assertSame(0, $archive->manifest['event_count']);
    }

    #[Test]
    public function exportComputesMacWhenKeyProvided(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $hmac = $this->createStub(HmacInterface::class);
        $hmac->method('computeHex')->willReturn('mac-hex-value');

        $exporter = new EvidenceExporter(
            store: $store,
            hmac: $hmac,
            archiveMacKey: 'secret-key',
        );

        $archive = $exporter->export();

        self::assertSame('mac-hex-value', $archive->mac);
    }

    #[Test]
    public function exportWithoutMacKeyProducesNullMac(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $exporter = new EvidenceExporter(store: $store);
        $archive = $exporter->export();

        self::assertNull($archive->mac);
    }

    #[Test]
    public function exportPassesFiltersToStore(): void
    {
        $receivedFilters = null;
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturnCallback(
            function (array $filters) use (&$receivedFilters): array {
                $receivedFilters = $filters;
                return [];
            },
        );

        $exporter = new EvidenceExporter(store: $store);
        $exporter->export(['event_type' => 'http.response']);

        self::assertSame(['event_type' => 'http.response'], $receivedFilters);
    }

    #[Test]
    public function exportManifestIncludesFilters(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $exporter = new EvidenceExporter(store: $store);
        $archive = $exporter->export(['since_us' => 1000]);

        self::assertSame(['since_us' => 1000], $archive->manifest['filters']);
    }
}
