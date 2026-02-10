<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Storage\DatabaseEventStore;
use Pulsar\Security\Crypto\HmacService;

use function hash;
use function json_encode;
use function usleep;

use const JSON_THROW_ON_ERROR;

#[CoversClass(DatabaseEventStore::class)]
final class DatabaseEventStoreTest extends TestCase
{
    private ConnectionInterface $connection;
    private DatabaseEventStore $store;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'studio_test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );
        $this->createSchema($this->connection);
        $this->store = new DatabaseEventStore($this->connection);
    }

    #[Test]
    public function storeInsertsEventSuccessfully(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode($envelope->payload, JSON_THROW_ON_ERROR);

        $this->store->store($envelope, $payloadJson);

        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function storeInsertsEventWithTenantHash(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode($envelope->payload, JSON_THROW_ON_ERROR);
        $tenantHash = hash('sha256', 'tenant-1');

        $this->store->store($envelope, $payloadJson, $tenantHash);

        $found = $this->store->find('event-1');
        self::assertNotNull($found);
        self::assertSame($tenantHash, $found['tenant_hash']);
    }

    #[Test]
    public function storeWithChainCreatesEventAndChainLink(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode($envelope->payload, JSON_THROW_ON_ERROR);

        $this->store->storeWithChain($envelope, $payloadJson, null, null);

        self::assertSame(1, $this->store->count());

        $links = $this->store->chainLinks();
        self::assertCount(1, $links);
        self::assertSame('event-1', $links[0]['event_id']);
    }

    #[Test]
    public function storeWithChainUsesCorrectSeedForFirstLink(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $expectedSeed = hash('sha256', 'PULSAR_STUDIO_CHAIN_SEED');

        $this->store->storeWithChain($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR), null, null);

        $links = $this->store->chainLinks();
        self::assertSame($expectedSeed, $links[0]['previous_hash']);
    }

    #[Test]
    public function storeWithChainLinksChainCorrectly(): void
    {
        $envelope1 = $this->createEnvelope('event-1');
        $envelope2 = $this->createEnvelope('event-2');

        $this->store->storeWithChain($envelope1, json_encode($envelope1->payload, JSON_THROW_ON_ERROR), null, null);
        $this->store->storeWithChain($envelope2, json_encode($envelope2->payload, JSON_THROW_ON_ERROR), null, null);

        $links = $this->store->chainLinks();
        self::assertCount(2, $links);

        // Second link's previous_hash should equal first link's current_hash
        self::assertSame($links[0]['current_hash'], $links[1]['previous_hash']);
    }

    #[Test]
    public function storeWithChainCreatesChainLinkWithMac(): void
    {
        $store = new DatabaseEventStore($this->connection, hmac: new HmacService());
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode($envelope->payload, JSON_THROW_ON_ERROR);
        $macKey = 'test-mac-key-32-bytes-length-ok!';

        $store->storeWithChain($envelope, $payloadJson, null, $macKey);

        $links = $store->chainLinks();
        self::assertCount(1, $links);
        self::assertNotNull($links[0]['link_mac']);
    }

    #[Test]
    public function chainIntegrityIsVerifiable(): void
    {
        $envelope1 = $this->createEnvelope('event-1');
        $envelope2 = $this->createEnvelope('event-2');
        $envelope3 = $this->createEnvelope('event-3');

        $this->store->storeWithChain($envelope1, json_encode($envelope1->payload, JSON_THROW_ON_ERROR), null, null);
        $this->store->storeWithChain($envelope2, json_encode($envelope2->payload, JSON_THROW_ON_ERROR), null, null);
        $this->store->storeWithChain($envelope3, json_encode($envelope3->payload, JSON_THROW_ON_ERROR), null, null);

        $links = $this->store->chainLinks();
        self::assertCount(3, $links);

        // Verify chain integrity: each link's current_hash = SHA-256(previous_hash | canonical)
        $seed = hash('sha256', 'PULSAR_STUDIO_CHAIN_SEED');
        self::assertSame($seed, $links[0]['previous_hash']);

        for ($i = 1; $i < 3; $i++) {
            self::assertSame(
                $links[$i - 1]['current_hash'],
                $links[$i]['previous_hash'],
                "Chain broken at link {$i}",
            );
        }
    }

    #[Test]
    public function queryReturnsAllEventsWithNoFilters(): void
    {
        $this->storeMultipleEvents(3);

        $results = $this->store->query();

        self::assertCount(3, $results);
    }

    #[Test]
    public function queryFiltersByEventType(): void
    {
        $this->store->store(
            $this->createEnvelope('http-1', EventType::HttpRequest),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('db-1', EventType::DatabaseQuery),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('http-2', EventType::HttpRequest),
            '{}',
        );

        $results = $this->store->query(['event_type' => EventType::HttpRequest->value]);

        self::assertCount(2, $results);
        foreach ($results as $result) {
            self::assertSame(EventType::HttpRequest->value, $result['event_type']);
        }
    }

    #[Test]
    public function queryFiltersByEventTypeArray(): void
    {
        $this->store->store(
            $this->createEnvelope('http-1', EventType::HttpRequest),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('db-1', EventType::DatabaseQuery),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('cache-1', EventType::CacheOperation),
            '{}',
        );

        $results = $this->store->query([
            'event_type' => [EventType::HttpRequest->value, EventType::DatabaseQuery->value],
        ]);

        self::assertCount(2, $results);
    }

    #[Test]
    public function queryRespectsLimitAndOffset(): void
    {
        $this->storeMultipleEvents(10);

        $results = $this->store->query([], 3, 2);

        self::assertCount(3, $results);
    }

    #[Test]
    public function queryOrdersByTimestampDescending(): void
    {
        $baseTimestamp = 1_700_000_000_000_000;

        $this->store->store(
            $this->createEnvelope('event-1', timestampUs: $baseTimestamp),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('event-2', timestampUs: $baseTimestamp + 1_000_000),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('event-3', timestampUs: $baseTimestamp + 2_000_000),
            '{}',
        );

        $results = $this->store->query();

        self::assertSame('event-3', $results[0]['event_id']);
        self::assertSame('event-2', $results[1]['event_id']);
        self::assertSame('event-1', $results[2]['event_id']);
    }

    #[Test]
    public function countReturnsZeroForEmptyStore(): void
    {
        self::assertSame(0, $this->store->count());
    }

    #[Test]
    public function countReturnsTotalEventCount(): void
    {
        $this->storeMultipleEvents(5);

        self::assertSame(5, $this->store->count());
    }

    #[Test]
    public function countWithEventTypeFilter(): void
    {
        $this->store->store(
            $this->createEnvelope('http-1', EventType::HttpRequest),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('db-1', EventType::DatabaseQuery),
            '{}',
        );

        self::assertSame(1, $this->store->count(['event_type' => EventType::HttpRequest->value]));
    }

    #[Test]
    public function findReturnsEventByEventId(): void
    {
        $envelope = $this->createEnvelope('test-event-id');
        $this->store->store($envelope, '{"key": "value"}');

        $found = $this->store->find('test-event-id');

        self::assertNotNull($found);
        self::assertSame('test-event-id', $found['event_id']);
        self::assertSame(EventType::HttpRequest->value, $found['event_type']);
        self::assertSame('{"key": "value"}', $found['payload_json']);
    }

    #[Test]
    public function findReturnsNullWhenEventNotFound(): void
    {
        $result = $this->store->find('non-existent-event');

        self::assertNull($result);
    }

    #[Test]
    public function sizeInBytesReturnsNonNegativeInteger(): void
    {
        $size = $this->store->sizeInBytes();

        self::assertGreaterThanOrEqual(0, $size);
    }

    #[Test]
    public function deleteOlderThanRemovesOldEvents(): void
    {
        $baseTimestamp = 1_700_000_000_000_000;

        $this->store->store(
            $this->createEnvelope('old-event', timestampUs: $baseTimestamp),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('new-event', timestampUs: $baseTimestamp + 2_000_000),
            '{}',
        );

        $deleted = $this->store->deleteOlderThan($baseTimestamp + 1_000_000);

        self::assertSame(1, $deleted);
        self::assertNull($this->store->find('old-event'));
        self::assertNotNull($this->store->find('new-event'));
    }

    #[Test]
    public function deleteByEventTypesRemovesMatchingEvents(): void
    {
        $this->store->store(
            $this->createEnvelope('http-1', EventType::HttpRequest),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('db-1', EventType::DatabaseQuery),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('cache-1', EventType::CacheOperation),
            '{}',
        );

        $deleted = $this->store->deleteByEventTypes([EventType::HttpRequest->value, EventType::DatabaseQuery->value]);

        self::assertSame(2, $deleted);
        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function deleteByEventTypesWithEmptyArrayDeletesNothing(): void
    {
        $this->store->store(
            $this->createEnvelope('http-1', EventType::HttpRequest),
            '{}',
        );

        $deleted = $this->store->deleteByEventTypes([]);

        self::assertSame(0, $deleted);
        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function deleteByPayloadKeyRemovesMatchingEvents(): void
    {
        $this->store->store(
            $this->createEnvelope('bench-1', EventType::BenchmarkProfile),
            '{"run_id":"abc123","profile_name":"default"}',
        );
        $this->store->store(
            $this->createEnvelope('bench-2', EventType::BenchmarkProfile),
            '{"run_id":"abc123","profile_name":"opcache"}',
        );
        $this->store->store(
            $this->createEnvelope('bench-3', EventType::BenchmarkProfile),
            '{"run_id":"def456","profile_name":"default"}',
        );

        $deleted = $this->store->deleteByPayloadKey(
            EventType::BenchmarkProfile->value,
            '$.run_id',
            'abc123',
        );

        self::assertSame(2, $deleted);
        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function clearRemovesAllEventsAndChainLinks(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $this->store->storeWithChain($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR), null, null);

        self::assertSame(1, $this->store->count());
        self::assertCount(1, $this->store->chainLinks());

        $this->store->clear();

        self::assertSame(0, $this->store->count());
        self::assertCount(0, $this->store->chainLinks());
    }

    #[Test]
    public function vacuumExecutesWithoutError(): void
    {
        $this->storeMultipleEvents(3);
        $this->store->clear();

        $this->store->vacuum();

        self::assertSame(0, $this->store->count());
    }

    #[Test]
    public function storePreservesAllEnvelopeFields(): void
    {
        $envelope = new EventEnvelope(
            eventId: 'full-test-id',
            eventType: EventType::DatabaseQuery,
            schemaVersion: EventVersion::V1,
            timestampUs: 1_700_000_000_000_000,
            requestId: 'req-full',
            traceId: 'trace-full',
            spanId: 'span-full',
            jobId: 'job-full',
            appEnv: 'production',
            hostname: 'server-01',
            payload: ['query' => 'SELECT * FROM users'],
            payloadHash: hash('sha256', '{}'),
        );

        $this->store->store($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR), 'tenant-hash');

        $found = $this->store->find('full-test-id');

        self::assertNotNull($found);
        self::assertSame('full-test-id', $found['event_id']);
        self::assertSame(EventType::DatabaseQuery->value, $found['event_type']);
        self::assertSame('req-full', $found['request_id']);
        self::assertSame('trace-full', $found['trace_id']);
        self::assertSame('span-full', $found['span_id']);
        self::assertSame('job-full', $found['job_id']);
        self::assertSame('production', $found['app_env']);
        self::assertSame('server-01', $found['hostname']);
        self::assertSame('tenant-hash', $found['tenant_hash']);
    }

    #[Test]
    public function chainLinksRespectsLimitAndOffset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $envelope = $this->createEnvelope("event-{$i}");
            $this->store->storeWithChain($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR), null, null);
            usleep(10);
        }

        $links = $this->store->chainLinks(2, 1);

        self::assertCount(2, $links);
    }

    private function createEnvelope(
        string $eventId,
        EventType $eventType = EventType::HttpRequest,
        ?string $requestId = 'req-123',
        ?string $traceId = 'trace-123',
        ?string $jobId = null,
        ?int $timestampUs = null,
    ): EventEnvelope {
        return new EventEnvelope(
            eventId: $eventId,
            eventType: $eventType,
            schemaVersion: EventVersion::V1,
            timestampUs: $timestampUs ?? (int) (microtime(true) * 1_000_000.0),
            requestId: $requestId,
            traceId: $traceId,
            spanId: 'span-123',
            jobId: $jobId,
            appEnv: 'testing',
            hostname: 'localhost',
            payload: ['method' => 'GET', 'uri' => '/test'],
            payloadHash: hash('sha256', '{}'),
        );
    }

    private function storeMultipleEvents(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $envelope = $this->createEnvelope("event-{$i}");
            $this->store->store($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR));
            usleep(10);
        }
    }

    private function createSchema(ConnectionInterface $connection): void
    {
        $connection->execute(<<<'SQL'
                CREATE TABLE IF NOT EXISTS studio_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    event_id TEXT NOT NULL UNIQUE,
                    event_type TEXT NOT NULL,
                    schema_version INTEGER NOT NULL DEFAULT 1,
                    timestamp_us INTEGER NOT NULL,
                    request_id TEXT,
                    trace_id TEXT,
                    span_id TEXT,
                    job_id TEXT,
                    app_env TEXT NOT NULL,
                    hostname TEXT NOT NULL,
                    tenant_hash TEXT,
                    payload_json TEXT NOT NULL,
                    payload_hash TEXT NOT NULL,
                    ciphertext_hash TEXT
                )
            SQL);

        $connection->execute(<<<'SQL'
                CREATE TABLE IF NOT EXISTS studio_chain (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    event_id TEXT NOT NULL UNIQUE REFERENCES studio_events(event_id) ON DELETE CASCADE,
                    previous_hash TEXT NOT NULL,
                    current_hash TEXT NOT NULL,
                    link_mac TEXT
                )
            SQL);

        $connection->execute('CREATE INDEX IF NOT EXISTS idx_events_ts ON studio_events(timestamp_us DESC)');
        $connection->execute('CREATE INDEX IF NOT EXISTS idx_events_type ON studio_events(event_type)');
    }
}
