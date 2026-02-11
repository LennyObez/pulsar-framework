<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventFactory;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Redaction\RedactionPipeline;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\StudioManager;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;
use RuntimeException;

use function hash;

#[CoversClass(StudioManager::class)]
final class StudioManagerTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private SqliteEventStore $store;

    /** @psalm-suppress PropertyNotSetInConstructor */
    private EventFactory $eventFactory;

    /** @psalm-suppress PropertyNotSetInConstructor */
    private RedactionPipeline $redactionPipeline;

    protected function setUp(): void
    {
        $this->store = SqliteEventStore::inMemory();
        $this->eventFactory = new EventFactory('testing', 'localhost');
        $this->redactionPipeline = new RedactionPipeline();
    }

    #[Test]
    public function ingestStoresEventInStore(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        $event = $this->createMockEvent();
        $manager->ingest($event);

        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function ingestStoresMultipleEvents(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        $manager->ingest($this->createMockEvent());
        $manager->ingest($this->createMockEvent());
        $manager->ingest($this->createMockEvent());

        self::assertSame(3, $this->store->count());
    }

    #[Test]
    public function ingestUsesProvidedCorrelationContext(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        $context = new CorrelationContext(
            requestId: 'req-123',
            traceId: 'trace-456',
            spanId: 'span-789',
            jobId: 'job-000',
        );

        $manager->ingest($this->createMockEvent(), $context);

        $results = $this->store->query();
        self::assertCount(1, $results);
        self::assertSame('req-123', $results[0]['request_id']);
        self::assertSame('trace-456', $results[0]['trace_id']);
        self::assertSame('span-789', $results[0]['span_id']);
        self::assertSame('job-000', $results[0]['job_id']);
    }

    #[Test]
    public function ingestResolvesAndStoresTenantHash(): void
    {
        $tenantContext = new TenantContext();
        $tenantContext->set(new Tenant('tenant-1', 'Test Tenant'));

        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            tenantContext: $tenantContext,
        );

        $manager->ingest($this->createMockEvent());

        $results = $this->store->query();
        self::assertCount(1, $results);
        self::assertSame(hash('sha256', 'tenant-1'), $results[0]['tenant_hash']);
    }

    #[Test]
    public function ingestStoresNullTenantHashWhenNoTenantResolved(): void
    {
        $tenantContext = new TenantContext();
        // No tenant set

        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            tenantContext: $tenantContext,
        );

        $manager->ingest($this->createMockEvent());

        $results = $this->store->query();
        self::assertCount(1, $results);
        self::assertNull($results[0]['tenant_hash']);
    }

    #[Test]
    public function ingestStoresNullTenantHashWhenNoTenantContext(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            tenantContext: null,
        );

        $manager->ingest($this->createMockEvent());

        $results = $this->store->query();
        self::assertCount(1, $results);
        self::assertNull($results[0]['tenant_hash']);
    }

    #[Test]
    public function ingestWithZeroSamplingRateStoresNoEvents(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            samplingRate: 0.0,
        );

        // Try to ingest multiple events
        for ($i = 0; $i < 100; $i++) {
            $manager->ingest($this->createMockEvent());
        }

        // With 0% sampling rate, no events should be stored
        self::assertSame(0, $this->store->count());
    }

    #[Test]
    public function ingestWithFullSamplingRateStoresAllEvents(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            samplingRate: 1.0,
        );

        for ($i = 0; $i < 10; $i++) {
            $manager->ingest($this->createMockEvent());
        }

        // With 100% sampling rate, all events should be stored
        self::assertSame(10, $this->store->count());
    }

    #[Test]
    public function ingestWithPartialSamplingRateStoresSomeEvents(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            samplingRate: 0.5,
        );

        // Ingest many events to get a statistical sample
        for ($i = 0; $i < 1000; $i++) {
            $manager->ingest($this->createMockEvent());
        }

        $count = $this->store->count();

        // With 50% sampling, we expect roughly 500 events (allowing for variance)
        // Using a loose bound: between 300 and 700 for 1000 events at 50% rate
        self::assertGreaterThan(300, $count);
        self::assertLessThan(700, $count);
    }

    #[Test]
    public function ingestAppliesRedactionPipeline(): void
    {
        // Create a pipeline that redacts 'password' keys
        $redactionPipeline = RedactionPipeline::withDefaults();

        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $redactionPipeline,
        );

        // Create an event with sensitive data
        $event = new class implements ConsoleEvent {
            public function eventType(): EventType
            {
                return EventType::HttpRequest;
            }

            public function schemaVersion(): EventVersion
            {
                return EventVersion::V1;
            }

            public function toArray(): array
            {
                return [
                    'method' => 'POST',
                    'password' => 'secret123',
                    'token' => 'abc123',
                ];
            }
        };

        $manager->ingest($event);

        $results = $this->store->query();
        self::assertCount(1, $results);

        /** @var string $payloadJson */
        $payloadJson = $results[0]['payload_json'];

        /** @var array<string, mixed> $payload */
        $payload = json_decode($payloadJson, true);

        // Default redaction policy should redact 'password' and 'token' keys
        self::assertSame('[REDACTED]', $payload['password']);
        self::assertSame('[REDACTED]', $payload['token']);
    }

    #[Test]
    public function ingestCreatesChainLinkForSqliteStore(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        $manager->ingest($this->createMockEvent());
        $manager->ingest($this->createMockEvent());

        $links = $this->store->chainLinks();
        self::assertCount(2, $links);
    }

    #[Test]
    public function ingestCreatesChainLinkWithMac(): void
    {
        $store = SqliteEventStore::inMemory(hmac: new HmacService());

        $manager = new StudioManager(
            store: $store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            chainMacKey: 'test-mac-key-32-bytes-length-ok!',
        );

        $manager->ingest($this->createMockEvent());

        $links = $store->chainLinks();
        self::assertCount(1, $links);
        self::assertNotNull($links[0]['link_mac']);
    }

    #[Test]
    public function ingestSwallowsExceptionsGracefully(): void
    {
        // Create a store that always throws
        $failingStore = new class implements EventStoreInterface {
            public function store(EventEnvelope $envelope, string $payloadJson, ?string $tenantHash = null): void
            {
                throw new RuntimeException('Store failed');
            }

            public function query(array $filters = [], int $limit = 50, int $offset = 0): array
            {
                return [];
            }

            public function count(array $filters = []): int
            {
                return 0;
            }

            public function find(string $eventId): ?array
            {
                return null;
            }

            public function sizeInBytes(): int
            {
                return 0;
            }

            public function deleteOlderThan(int $timestampUs): int
            {
                return 0;
            }

            public function deleteByEventTypes(array $eventTypes): int
            {
                return 0;
            }

            public function deleteByPayloadKey(string $eventType, string $jsonPath, string $value): int
            {
                return 0;
            }

            public function clear(): void {}

            public function vacuum(): void {}
        };

        $manager = new StudioManager(
            store: $failingStore,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        // Should not throw
        $manager->ingest($this->createMockEvent());

        // If we get here, exception was swallowed as expected
        self::assertSame(0, $failingStore->count());
    }

    #[Test]
    public function emitCallbackReturnsCallableForIngest(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        $callback = $manager->emitCallback();

        self::assertInstanceOf(Closure::class, $callback);

        // Use the callback to ingest an event
        $callback($this->createMockEvent(), null);

        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function emitCallbackAcceptsCorrelationContext(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        $callback = $manager->emitCallback();
        $context = new CorrelationContext(
            requestId: 'emit-req',
            traceId: 'emit-trace',
        );

        $callback($this->createMockEvent(), $context);

        $results = $this->store->query();
        self::assertSame('emit-req', $results[0]['request_id']);
        self::assertSame('emit-trace', $results[0]['trace_id']);
    }

    #[Test]
    public function storeReturnsUnderlyingStore(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        $store = $manager->store();

        self::assertSame($this->store, $store);
    }

    #[Test]
    public function ingestHandlesNullContext(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        $manager->ingest($this->createMockEvent(), null);

        self::assertSame(1, $this->store->count());

        $results = $this->store->query();
        self::assertNull($results[0]['request_id']);
        self::assertNull($results[0]['trace_id']);
        self::assertNull($results[0]['span_id']);
        self::assertNull($results[0]['job_id']);
    }

    #[Test]
    public function ingestStoresCorrectEventType(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        $event = new class implements ConsoleEvent {
            public function eventType(): EventType
            {
                return EventType::DatabaseQuery;
            }

            public function schemaVersion(): EventVersion
            {
                return EventVersion::V1;
            }

            public function toArray(): array
            {
                return ['query' => 'SELECT 1'];
            }
        };

        $manager->ingest($event);

        $results = $this->store->query();
        self::assertSame(EventType::DatabaseQuery->value, $results[0]['event_type']);
    }

    #[Test]
    public function ingestStoresCorrectSchemaVersion(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        $manager->ingest($this->createMockEvent());

        $results = $this->store->query();
        self::assertSame(EventVersion::V1->value, $results[0]['schema_version']);
    }

    /**
     * Create a simple mock event for testing.
     */
    private function createMockEvent(): ConsoleEvent
    {
        return new class implements ConsoleEvent {
            public function eventType(): EventType
            {
                return EventType::HttpRequest;
            }

            public function schemaVersion(): EventVersion
            {
                return EventVersion::V1;
            }

            public function toArray(): array
            {
                return ['method' => 'GET', 'uri' => '/test'];
            }
        };
    }
}
