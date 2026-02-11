<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventFactory;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Redaction\RedactionPipeline;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\StudioManager;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Tenancy\Tenant;
use Pulsar\Tenancy\TenantContext;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

#[CoversClass(StudioManager::class)]
final class StudioManagerTest extends TestCase
{
    private SqliteEventStore $store;
    private EventFactory $eventFactory;
    private RedactionPipeline $redactionPipeline;

    protected function setUp(): void
    {
        $this->store = SqliteEventStore::inMemory();
        $this->eventFactory = new EventFactory(
            appEnv: 'test',
            hostname: 'localhost',
            randomizer: new Randomizer(new Mt19937(12345)),
        );
        $this->redactionPipeline = new RedactionPipeline();
    }

    private function createEvent(string $method = 'GET', string $uri = '/test'): ConsoleEvent
    {
        return new readonly class ($method, $uri) implements ConsoleEvent {
            public function __construct(
                private string $method,
                private string $uri,
            ) {}

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
                    'method' => $this->method,
                    'uri' => $this->uri,
                ];
            }
        };
    }

    #[Test]
    public function ingestStoresEventInStore(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            randomizer: new Randomizer(new Mt19937(54321)),
        );

        $event = $this->createEvent();
        $context = new CorrelationContext(requestId: 'req-1');

        $manager->ingest($event, $context);

        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function ingestAppliesRedactionPipeline(): void
    {
        $pipeline = RedactionPipeline::withDefaults();

        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $pipeline,
            randomizer: new Randomizer(new Mt19937(54321)),
        );

        $event = new readonly class implements ConsoleEvent {
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
                ];
            }
        };

        $manager->ingest($event);

        $events = $this->store->query(['event_type' => 'http.request']);
        self::assertCount(1, $events);

        // Password should be redacted
        $payloadJson = $events[0]['payload_json'];
        self::assertIsString($payloadJson);
        self::assertStringNotContainsString('secret123', $payloadJson);
    }

    #[Test]
    public function ingestResolvesTenatHash(): void
    {
        $tenantContext = new TenantContext();
        $tenantContext->set(new Tenant(id: 'tenant-1', name: 'Acme Corp'));

        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            tenantContext: $tenantContext,
            randomizer: new Randomizer(new Mt19937(54321)),
        );

        $manager->ingest($this->createEvent());

        $events = $this->store->query();
        self::assertCount(1, $events);
        self::assertSame(hash('sha256', 'tenant-1'), $events[0]['tenant_hash']);
    }

    #[Test]
    public function ingestHandlesNullTenantContext(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            tenantContext: null,
            randomizer: new Randomizer(new Mt19937(54321)),
        );

        $manager->ingest($this->createEvent());

        $events = $this->store->query();
        self::assertCount(1, $events);
        self::assertNull($events[0]['tenant_hash']);
    }

    #[Test]
    public function ingestHandlesUnresolvedTenant(): void
    {
        $tenantContext = new TenantContext();

        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            tenantContext: $tenantContext,
            randomizer: new Randomizer(new Mt19937(54321)),
        );

        $manager->ingest($this->createEvent());

        $events = $this->store->query();
        self::assertNull($events[0]['tenant_hash']);
    }

    #[Test]
    public function ingestWithSamplingAtOneHundredPercentAlwaysStores(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            samplingRate: 1.0,
            randomizer: new Randomizer(new Mt19937(54321)),
        );

        for ($i = 0; $i < 10; $i++) {
            $manager->ingest($this->createEvent());
        }

        self::assertSame(10, $this->store->count());
    }

    #[Test]
    public function ingestWithZeroSamplingRateStoresNothing(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            samplingRate: 0.0,
            randomizer: new Randomizer(new Mt19937(54321)),
        );

        for ($i = 0; $i < 10; $i++) {
            $manager->ingest($this->createEvent());
        }

        self::assertSame(0, $this->store->count());
    }

    #[Test]
    public function ingestWithPartialSamplingStoresSomeEvents(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            samplingRate: 0.5,
            randomizer: new Randomizer(new Mt19937(54321)),
        );

        for ($i = 0; $i < 100; $i++) {
            $manager->ingest($this->createEvent());
        }

        $count = $this->store->count();
        // With 50% sampling over 100 events, we expect roughly 40-60
        self::assertGreaterThan(20, $count);
        self::assertLessThan(80, $count);
    }

    #[Test]
    public function emitCallbackReturnsCallableForIngest(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            randomizer: new Randomizer(new Mt19937(54321)),
        );

        $callback = $manager->emitCallback();

        $callback($this->createEvent(), new CorrelationContext());

        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function storeReturnsUnderlyingEventStore(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        self::assertSame($this->store, $manager->store());
    }

    #[Test]
    public function ingestSwallowsExceptions(): void
    {
        // StudioManager should never crash the app, even with broken events
        $brokenEvent = new readonly class implements ConsoleEvent {
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
                throw new RuntimeException('broken event');
            }
        };

        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
        );

        // This should not throw
        $manager->ingest($brokenEvent);

        self::assertSame(0, $this->store->count());
    }

    #[Test]
    public function ingestStoresWithChainOnSqliteStore(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            randomizer: new Randomizer(new Mt19937(54321)),
        );

        $manager->ingest($this->createEvent());
        $manager->ingest($this->createEvent('POST', '/api'));

        $chainLinks = $this->store->chainLinks();
        self::assertCount(2, $chainLinks);
    }

    #[Test]
    public function ingestWithChainMacKey(): void
    {
        $manager = new StudioManager(
            store: $this->store,
            eventFactory: $this->eventFactory,
            redactionPipeline: $this->redactionPipeline,
            chainMacKey: 'test-mac-key',
            randomizer: new Randomizer(new Mt19937(54321)),
        );

        // This should not fail even without an HMAC implementation in the store
        $manager->ingest($this->createEvent());
        self::assertSame(1, $this->store->count());
    }
}
