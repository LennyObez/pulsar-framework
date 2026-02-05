<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Storage;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Security\Crypto\HmacService;

use function hash;
use function json_encode;
use function usleep;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SqliteEventStore::class)]
final class SqliteEventStoreTest extends TestCase
{
    private SqliteEventStore $store;

    protected function setUp(): void
    {
        $this->store = SqliteEventStore::inMemory();
    }

    #[Test]
    public function inMemoryCreatesWorkingStore(): void
    {
        $store = SqliteEventStore::inMemory();

        self::assertInstanceOf(SqliteEventStore::class, $store);
        self::assertSame(0, $store->count());
    }

    #[Test]
    public function inMemoryAcceptsOptionalMetricRegistry(): void
    {
        $registry = new MetricRegistry();
        $store = SqliteEventStore::inMemory($registry);

        self::assertInstanceOf(SqliteEventStore::class, $store);
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
    public function storeWithChainCreatesChainLinkWithMac(): void
    {
        $store = new SqliteEventStore(':memory:', null, new HmacService());
        $envelope = $this->createEnvelope('event-1');
        $payloadJson = json_encode($envelope->payload, JSON_THROW_ON_ERROR);
        $macKey = 'test-mac-key-32-bytes-length-ok!';

        $store->storeWithChain($envelope, $payloadJson, null, $macKey);

        $links = $store->chainLinks();
        self::assertCount(1, $links);
        self::assertNotNull($links[0]['link_mac']);
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
    public function storeWithChainUsesCorrectSeedForFirstLink(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $expectedSeed = hash('sha256', 'PULSAR_STUDIO_CHAIN_SEED');

        $this->store->storeWithChain($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR), null, null);

        $links = $this->store->chainLinks();
        self::assertSame($expectedSeed, $links[0]['previous_hash']);
    }

    #[Test]
    public function queryReturnsAllEventsWithNoFilters(): void
    {
        $this->storeMultipleEvents(3);

        $results = $this->store->query();

        self::assertCount(3, $results);
    }

    #[Test]
    public function queryFiltersbyEventType(): void
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
    public function queryFiltersByRequestId(): void
    {
        $this->store->store(
            $this->createEnvelope('event-1', requestId: 'req-123'),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('event-2', requestId: 'req-456'),
            '{}',
        );

        $results = $this->store->query(['request_id' => 'req-123']);

        self::assertCount(1, $results);
        self::assertSame('req-123', $results[0]['request_id']);
    }

    #[Test]
    public function queryFiltersByTraceId(): void
    {
        $this->store->store(
            $this->createEnvelope('event-1', traceId: 'trace-abc'),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('event-2', traceId: 'trace-xyz'),
            '{}',
        );

        $results = $this->store->query(['trace_id' => 'trace-abc']);

        self::assertCount(1, $results);
        self::assertSame('trace-abc', $results[0]['trace_id']);
    }

    #[Test]
    public function queryFiltersByJobId(): void
    {
        $this->store->store(
            $this->createEnvelope('event-1', jobId: 'job-1'),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('event-2', jobId: 'job-2'),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('event-3', jobId: null),
            '{}',
        );

        $results = $this->store->query(['job_id' => 'job-1']);

        self::assertCount(1, $results);
        self::assertSame('job-1', $results[0]['job_id']);
    }

    #[Test]
    public function queryFiltersByTenantHash(): void
    {
        $tenantHash1 = hash('sha256', 'tenant-1');
        $tenantHash2 = hash('sha256', 'tenant-2');

        $this->store->store(
            $this->createEnvelope('event-1'),
            '{}',
            $tenantHash1,
        );
        $this->store->store(
            $this->createEnvelope('event-2'),
            '{}',
            $tenantHash2,
        );

        $results = $this->store->query(['tenant_hash' => $tenantHash1]);

        self::assertCount(1, $results);
        self::assertSame($tenantHash1, $results[0]['tenant_hash']);
    }

    #[Test]
    public function queryFiltersBySinceUs(): void
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

        $results = $this->store->query(['since_us' => $baseTimestamp + 500_000]);

        self::assertCount(2, $results);
    }

    #[Test]
    public function queryFiltersByUntilUs(): void
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

        $results = $this->store->query(['until_us' => $baseTimestamp + 1_500_000]);

        self::assertCount(2, $results);
    }

    #[Test]
    public function queryFiltersBySinceId(): void
    {
        $this->storeMultipleEvents(5);

        // Get the first two events
        $allEvents = $this->store->query([], 5);
        // Events are ordered DESC by timestamp, so we need to find an ID to use
        $results = $this->store->query(['since_id' => 2]);

        self::assertCount(3, $results);
    }

    #[Test]
    public function queryWithMultipleFilters(): void
    {
        $baseTimestamp = 1_700_000_000_000_000;

        $this->store->store(
            $this->createEnvelope(
                'event-1',
                EventType::HttpRequest,
                'req-123',
                'trace-abc',
                null,
                $baseTimestamp,
            ),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope(
                'event-2',
                EventType::HttpRequest,
                'req-123',
                'trace-xyz',
                null,
                $baseTimestamp + 1_000_000,
            ),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope(
                'event-3',
                EventType::DatabaseQuery,
                'req-123',
                'trace-abc',
                null,
                $baseTimestamp + 2_000_000,
            ),
            '{}',
        );

        $results = $this->store->query([
            'event_type' => EventType::HttpRequest->value,
            'request_id' => 'req-123',
            'trace_id' => 'trace-abc',
        ]);

        self::assertCount(1, $results);
        self::assertSame('event-1', $results[0]['event_id']);
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
        $this->store->store(
            $this->createEnvelope('http-2', EventType::HttpRequest),
            '{}',
        );

        self::assertSame(2, $this->store->count(['event_type' => EventType::HttpRequest->value]));
    }

    #[Test]
    public function countWithEventTypeArrayFilter(): void
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

        self::assertSame(2, $this->store->count([
            'event_type' => [EventType::HttpRequest->value, EventType::DatabaseQuery->value],
        ]));
    }

    #[Test]
    public function countWithSinceUsFilter(): void
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

        self::assertSame(2, $this->store->count(['since_us' => $baseTimestamp + 500_000]));
    }

    #[Test]
    public function countWithUntilUsFilter(): void
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

        self::assertSame(2, $this->store->count(['until_us' => $baseTimestamp + 1_500_000]));
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
    public function sizeInBytesIncreasesAfterStoringEvents(): void
    {
        $sizeBefore = $this->store->sizeInBytes();

        $this->storeMultipleEvents(10);

        $sizeAfter = $this->store->sizeInBytes();

        self::assertGreaterThanOrEqual($sizeBefore, $sizeAfter);
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
    public function deleteOlderThanReturnsZeroWhenNothingDeleted(): void
    {
        $baseTimestamp = 1_700_000_000_000_000;

        $this->store->store(
            $this->createEnvelope('event-1', timestampUs: $baseTimestamp + 1_000_000),
            '{}',
        );

        $deleted = $this->store->deleteOlderThan($baseTimestamp);

        self::assertSame(0, $deleted);
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
        self::assertNotNull($this->store->find('cache-1'));
    }

    #[Test]
    public function deleteByEventTypesReturnsCount(): void
    {
        $this->store->store(
            $this->createEnvelope('http-1', EventType::HttpRequest),
            '{}',
        );
        $this->store->store(
            $this->createEnvelope('http-2', EventType::HttpRequest),
            '{}',
        );

        $deleted = $this->store->deleteByEventTypes([EventType::HttpRequest->value]);

        self::assertSame(2, $deleted);
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
        self::assertNotNull($this->store->find('bench-3'));
    }

    #[Test]
    public function deleteByPayloadKeyDoesNotAffectOtherTypes(): void
    {
        $this->store->store(
            $this->createEnvelope('profile-1', EventType::BenchmarkProfile),
            '{"run_id":"abc123"}',
        );
        $this->store->store(
            $this->createEnvelope('run-1', EventType::BenchmarkRun),
            '{"run_id":"abc123"}',
        );

        $deleted = $this->store->deleteByPayloadKey(
            EventType::BenchmarkProfile->value,
            '$.run_id',
            'abc123',
        );

        self::assertSame(1, $deleted);
        self::assertSame(1, $this->store->count());
        self::assertNotNull($this->store->find('run-1'));
    }

    #[Test]
    public function clearRemovesAllEvents(): void
    {
        $this->storeMultipleEvents(5);

        $this->store->clear();

        self::assertSame(0, $this->store->count());
    }

    #[Test]
    public function clearAlsoRemovesChainLinks(): void
    {
        $envelope = $this->createEnvelope('event-1');
        $this->store->storeWithChain($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR), null, null);

        self::assertCount(1, $this->store->chainLinks());

        $this->store->clear();

        self::assertCount(0, $this->store->chainLinks());
    }

    #[Test]
    public function vacuumExecutesWithoutError(): void
    {
        $this->storeMultipleEvents(5);
        $this->store->clear();

        // Should not throw
        $this->store->vacuum();

        self::assertSame(0, $this->store->count());
    }

    #[Test]
    public function chainLinksReturnsEmptyArrayWhenNoChainLinks(): void
    {
        $links = $this->store->chainLinks();

        self::assertSame([], $links);
    }

    #[Test]
    public function chainLinksReturnsLinksWithEventData(): void
    {
        $envelope = $this->createEnvelope('event-1', EventType::HttpRequest);
        $this->store->storeWithChain($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR), null, null);

        $links = $this->store->chainLinks();

        self::assertCount(1, $links);
        self::assertSame('event-1', $links[0]['event_id']);
        self::assertSame(EventType::HttpRequest->value, $links[0]['event_type']);
        self::assertArrayHasKey('previous_hash', $links[0]);
        self::assertArrayHasKey('current_hash', $links[0]);
    }

    #[Test]
    public function chainLinksOrdersByIdAscending(): void
    {
        $envelope1 = $this->createEnvelope('event-1');
        $envelope2 = $this->createEnvelope('event-2');
        $envelope3 = $this->createEnvelope('event-3');

        $this->store->storeWithChain($envelope1, json_encode($envelope1->payload, JSON_THROW_ON_ERROR), null, null);
        $this->store->storeWithChain($envelope2, json_encode($envelope2->payload, JSON_THROW_ON_ERROR), null, null);
        $this->store->storeWithChain($envelope3, json_encode($envelope3->payload, JSON_THROW_ON_ERROR), null, null);

        $links = $this->store->chainLinks();

        self::assertSame('event-1', $links[0]['event_id']);
        self::assertSame('event-2', $links[1]['event_id']);
        self::assertSame('event-3', $links[2]['event_id']);
    }

    #[Test]
    public function chainLinksRespectsLimit(): void
    {
        $this->storeMultipleEventsWithChain(5);

        $links = $this->store->chainLinks(2);

        self::assertCount(2, $links);
    }

    #[Test]
    public function chainLinksRespectsOffset(): void
    {
        $this->storeMultipleEventsWithChain(5);

        $links = $this->store->chainLinks(2, 2);

        self::assertCount(2, $links);
    }

    #[Test]
    public function chainLinksWithZeroLimitReturnsAll(): void
    {
        $this->storeMultipleEventsWithChain(5);

        $links = $this->store->chainLinks(0);

        self::assertCount(5, $links);
    }

    #[Test]
    public function getMetaReturnsNullForNonExistentKey(): void
    {
        $value = $this->store->getMeta('non-existent');

        self::assertNull($value);
    }

    #[Test]
    public function setMetaAndGetMetaWorkTogether(): void
    {
        $this->store->setMeta('test_key', 'test_value');

        $value = $this->store->getMeta('test_key');

        self::assertSame('test_value', $value);
    }

    #[Test]
    public function setMetaOverwritesExistingValue(): void
    {
        $this->store->setMeta('key', 'value1');
        $this->store->setMeta('key', 'value2');

        $value = $this->store->getMeta('key');

        self::assertSame('value2', $value);
    }

    #[Test]
    public function incrementMetaIncrementsExistingValue(): void
    {
        $this->store->setMeta('counter', '5');

        $this->store->incrementMeta('counter');

        self::assertSame('6', $this->store->getMeta('counter'));
    }

    #[Test]
    public function incrementMetaCreatesNewKeyWithIncrementValue(): void
    {
        $this->store->incrementMeta('new_counter');

        self::assertSame('1', $this->store->getMeta('new_counter'));
    }

    #[Test]
    public function incrementMetaWithCustomIncrement(): void
    {
        $this->store->setMeta('counter', '10');

        $this->store->incrementMeta('counter', 5);

        self::assertSame('15', $this->store->getMeta('counter'));
    }

    #[Test]
    public function incrementMetaWithNegativeIncrement(): void
    {
        $this->store->setMeta('counter', '10');

        $this->store->incrementMeta('counter', -3);

        self::assertSame('7', $this->store->getMeta('counter'));
    }

    #[Test]
    public function pdoReturnsUnderlyingConnection(): void
    {
        $pdo = $this->store->pdo();

        self::assertInstanceOf(PDO::class, $pdo);
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
        self::assertSame(EventVersion::V1->value, $found['schema_version']);
        self::assertSame(1_700_000_000_000_000, $found['timestamp_us']);
        self::assertSame('req-full', $found['request_id']);
        self::assertSame('trace-full', $found['trace_id']);
        self::assertSame('span-full', $found['span_id']);
        self::assertSame('job-full', $found['job_id']);
        self::assertSame('production', $found['app_env']);
        self::assertSame('server-01', $found['hostname']);
        self::assertSame('tenant-hash', $found['tenant_hash']);
    }

    #[Test]
    public function storeWithNullableFieldsAsNull(): void
    {
        $envelope = new EventEnvelope(
            eventId: 'nullable-test',
            eventType: EventType::Heartbeat,
            schemaVersion: EventVersion::V1,
            timestampUs: 1_700_000_000_000_000,
            requestId: null,
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'testing',
            hostname: 'localhost',
            payload: [],
            payloadHash: hash('sha256', '{}'),
        );

        $this->store->store($envelope, '{}');

        $found = $this->store->find('nullable-test');

        self::assertNotNull($found);
        self::assertNull($found['request_id']);
        self::assertNull($found['trace_id']);
        self::assertNull($found['span_id']);
        self::assertNull($found['job_id']);
        self::assertNull($found['tenant_hash']);
    }

    #[Test]
    public function queryReturnsCompleteEventData(): void
    {
        $envelope = $this->createEnvelope('query-test');
        $this->store->store($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR));

        $results = $this->store->query();

        self::assertCount(1, $results);
        self::assertArrayHasKey('id', $results[0]);
        self::assertArrayHasKey('event_id', $results[0]);
        self::assertArrayHasKey('event_type', $results[0]);
        self::assertArrayHasKey('schema_version', $results[0]);
        self::assertArrayHasKey('timestamp_us', $results[0]);
        self::assertArrayHasKey('request_id', $results[0]);
        self::assertArrayHasKey('trace_id', $results[0]);
        self::assertArrayHasKey('span_id', $results[0]);
        self::assertArrayHasKey('job_id', $results[0]);
        self::assertArrayHasKey('app_env', $results[0]);
        self::assertArrayHasKey('hostname', $results[0]);
        self::assertArrayHasKey('tenant_hash', $results[0]);
        self::assertArrayHasKey('payload_json', $results[0]);
        self::assertArrayHasKey('payload_hash', $results[0]);
    }

    /**
     * Helper to create a test EventEnvelope.
     */
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

    /**
     * Helper to store multiple events.
     */
    private function storeMultipleEvents(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $envelope = $this->createEnvelope("event-{$i}");
            $this->store->store($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR));
            // Small delay to ensure unique timestamps
            usleep(10);
        }
    }

    /**
     * Helper to store multiple events with chain links.
     */
    private function storeMultipleEventsWithChain(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $envelope = $this->createEnvelope("event-{$i}");
            $this->store->storeWithChain($envelope, json_encode($envelope->payload, JSON_THROW_ON_ERROR), null, null);
            // Small delay to ensure unique timestamps
            usleep(10);
        }
    }
}
