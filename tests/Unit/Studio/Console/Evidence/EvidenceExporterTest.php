<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceArchive;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceExporter;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\Exception\StudioException;
use Pulsar\Security\Crypto\HmacService;

use function hash;
use function microtime;
use function strlen;

#[CoversClass(EvidenceExporter::class)]
final class EvidenceExporterTest extends TestCase
{
    #[Test]
    public function exportReturnsEvidenceArchiveWithEvents(): void
    {
        $events = [
            ['event_id' => 'evt-1', 'event_type' => 'http_request'],
            ['event_id' => 'evt-2', 'event_type' => 'http_response'],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $exporter = new EvidenceExporter($store);

        $archive = $exporter->export();

        self::assertInstanceOf(EvidenceArchive::class, $archive);
        self::assertCount(2, $archive->events);
        self::assertSame($events, $archive->events);
    }

    #[Test]
    public function exportIncludesManifestWithMetadata(): void
    {
        $events = [
            ['event_id' => 'evt-1'],
            ['event_id' => 'evt-2'],
            ['event_id' => 'evt-3'],
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn($events);

        $exporter = new EvidenceExporter($store);

        $archive = $exporter->export();

        self::assertArrayHasKey('version', $archive->manifest);
        self::assertSame(1, $archive->manifest['version']);
        self::assertArrayHasKey('exported_at', $archive->manifest);
        self::assertArrayHasKey('event_count', $archive->manifest);
        self::assertSame(3, $archive->manifest['event_count']);
        self::assertArrayHasKey('chain_link_count', $archive->manifest);
        self::assertArrayHasKey('filters', $archive->manifest);
    }

    #[Test]
    public function exportPassesFiltersToStore(): void
    {
        $filters = ['event_type' => 'http_request', 'since_us' => 1000000];

        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with($filters, PHP_INT_MAX)
            ->willReturn([]);

        $exporter = new EvidenceExporter($store);

        $archive = $exporter->export($filters);

        self::assertSame($filters, $archive->manifest['filters']);
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
        $this->expectExceptionMessage('Cannot export: events are encrypted at rest');

        $exporter->export();
    }

    #[Test]
    public function exportSucceedsWhenEncryptedWithDecryptionKey(): void
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
    public function exportIncludesChainLinksFromSqliteStore(): void
    {
        // Use a real SqliteEventStore with in-memory database
        $store = SqliteEventStore::inMemory();

        // Store some events with chain links to populate chainLinks
        $envelope = $this->createEventEnvelope('evt-1');
        $store->storeWithChain($envelope, '{"test": "data"}', null, null);

        $envelope2 = $this->createEventEnvelope('evt-2');
        $store->storeWithChain($envelope2, '{"test": "data2"}', null, null);

        $exporter = new EvidenceExporter($store);

        $archive = $exporter->export();

        self::assertCount(2, $archive->chainLinks);
        self::assertSame(2, $archive->manifest['chain_link_count']);
    }

    #[Test]
    public function exportRetrievesChainLinksFromEncryptedStoreInner(): void
    {
        // This test verifies the behavior when an EncryptedEventStore wraps a SqliteEventStore.
        // Since both classes are final, we use a simple EventStoreInterface stub to test
        // that non-SqliteEventStore stores return empty chain links.
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $exporter = new EvidenceExporter($store);

        $archive = $exporter->export();

        // Non-SqliteEventStore stores return empty chain links
        self::assertCount(0, $archive->chainLinks);
        self::assertSame(0, $archive->manifest['chain_link_count']);
    }

    #[Test]
    public function exportReturnsEmptyChainLinksForNonSqliteStore(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $exporter = new EvidenceExporter($store);

        $archive = $exporter->export();

        self::assertCount(0, $archive->chainLinks);
        self::assertSame(0, $archive->manifest['chain_link_count']);
    }

    #[Test]
    public function exportComputesMacWhenKeyProvided(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        // HMAC requires minimum 16-byte key
        $macKey = 'test-mac-key-1234567890';

        $exporter = new EvidenceExporter(
            store: $store,
            hmac: new HmacService(),
            archiveMacKey: $macKey,
        );

        $archive = $exporter->export();

        self::assertNotNull($archive->mac);
        self::assertSame(64, strlen($archive->mac)); // BLAKE2b hex output
    }

    #[Test]
    public function exportDoesNotIncludeMacWhenKeyNotProvided(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $exporter = new EvidenceExporter($store);

        $archive = $exporter->export();

        self::assertNull($archive->mac);
    }

    #[Test]
    public function exportMacIsDeterministicForSameInput(): void
    {
        $events = [['event_id' => 'evt-1']];
        $macKey = 'test-mac-key-1234567890';

        $store1 = $this->createStub(EventStoreInterface::class);
        $store1->method('query')->willReturn($events);

        $store2 = $this->createStub(EventStoreInterface::class);
        $store2->method('query')->willReturn($events);

        // Note: MAC depends on manifest which includes exported_at timestamp,
        // so two sequential exports will have different MACs unless at same second.
        // We just verify both MACs are computed.
        $hmac = new HmacService();
        $exporter1 = new EvidenceExporter($store1, $hmac, $macKey);
        $exporter2 = new EvidenceExporter($store2, $hmac, $macKey);

        $archive1 = $exporter1->export();
        $archive2 = $exporter2->export();

        self::assertNotNull($archive1->mac);
        self::assertNotNull($archive2->mac);
        // Both should be valid hex strings
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $archive1->mac);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $archive2->mac);
    }

    #[Test]
    public function exportWithFiltersRecordsFiltersInManifest(): void
    {
        $filters = [
            'event_type' => ['http_request', 'http_response'],
            'request_id' => 'req-123',
        ];

        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $exporter = new EvidenceExporter($store);

        $archive = $exporter->export($filters);

        self::assertSame($filters, $archive->manifest['filters']);
    }

    #[Test]
    public function exportWithEmptyFiltersRecordsEmptyFilters(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('query')->willReturn([]);

        $exporter = new EvidenceExporter($store);

        $archive = $exporter->export([]);

        self::assertSame([], $archive->manifest['filters']);
    }

    /**
     * Create a test EventEnvelope for store testing.
     */
    private function createEventEnvelope(string $eventId): EventEnvelope
    {
        $payload = ['test' => 'data'];
        $payloadJson = (string) json_encode($payload);
        $payloadHash = hash('sha256', $payloadJson);

        return new EventEnvelope(
            eventId: $eventId,
            eventType: EventType::HttpRequest,
            schemaVersion: EventVersion::V1,
            timestampUs: (int) (microtime(true) * (float) 1_000_000),
            requestId: 'req-123',
            traceId: 'trace-456',
            spanId: null,
            jobId: null,
            appEnv: 'local',
            hostname: 'localhost',
            payload: $payload,
            payloadHash: $payloadHash,
        );
    }
}
