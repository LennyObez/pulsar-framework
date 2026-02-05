<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Retention;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Retention\RetentionEnforcer;
use Pulsar\Extension\Studio\Console\Retention\RetentionPolicy;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Observability\Metrics\MetricRegistry;

use function json_encode;
use function microtime;
use function time;

use const JSON_THROW_ON_ERROR;

#[CoversClass(RetentionEnforcer::class)]
final class RetentionEnforcerTest extends TestCase
{
    private SqliteEventStore $store;

    protected function setUp(): void
    {
        $this->store = SqliteEventStore::inMemory();
    }

    private function nowUs(): int
    {
        return (int) (microtime(true) * 1_000_000.0);
    }

    private function insertEvent(int $timestampUs): void
    {
        $payloadJson = json_encode(['data' => 'test'], JSON_THROW_ON_ERROR);
        $eventId = bin2hex(random_bytes(16));

        $envelope = new EventEnvelope(
            eventId: $eventId,
            eventType: EventType::HttpResponse,
            schemaVersion: EventVersion::V1,
            timestampUs: $timestampUs,
            requestId: null,
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'test',
            hostname: 'localhost',
            payload: ['data' => 'test'],
            payloadHash: hash('sha256', $payloadJson),
        );

        $this->store->storeWithChain($envelope, $payloadJson, null, null);
    }

    #[Test]
    public function enforceDeletesOldEvents(): void
    {
        // Insert events: some old, some recent
        $oldTimestamp = $this->nowUs() - (8 * 86400 * 1_000_000); // 8 days ago
        $this->insertEvent($oldTimestamp);
        $this->insertEvent($oldTimestamp - 1_000_000);

        $recentTimestamp = $this->nowUs();
        $this->insertEvent($recentTimestamp);

        $policy = new RetentionPolicy(maxAgeDays: 7, maxSizeMb: 500);
        $enforcer = new RetentionEnforcer($this->store, $policy);

        $result = $enforcer->enforce();

        self::assertSame(2, $result['events_deleted']);
        self::assertSame(1, $this->store->count());
    }

    #[Test]
    public function enforceRunsVacuumWhenNeverRun(): void
    {
        $policy = new RetentionPolicy(maxAgeDays: 7, maxSizeMb: 500, vacuumIntervalHours: 24);
        $enforcer = new RetentionEnforcer($this->store, $policy);

        $result = $enforcer->enforce();

        self::assertTrue($result['vacuum_run']);
        self::assertNotNull($this->store->getMeta('last_vacuum'));
    }

    #[Test]
    public function enforceSkipsVacuumWhenRecentlyRun(): void
    {
        $this->store->setMeta('last_vacuum', (string) time());

        $policy = new RetentionPolicy(maxAgeDays: 7, maxSizeMb: 500, vacuumIntervalHours: 24);
        $enforcer = new RetentionEnforcer($this->store, $policy);

        $result = $enforcer->enforce();

        self::assertFalse($result['vacuum_run']);
    }

    #[Test]
    public function enforceRunsVacuumWhenIntervalElapsed(): void
    {
        $this->store->setMeta('last_vacuum', (string) (time() - 90000)); // 25 hours ago

        $policy = new RetentionPolicy(maxAgeDays: 7, maxSizeMb: 500, vacuumIntervalHours: 24);
        $enforcer = new RetentionEnforcer($this->store, $policy);

        $result = $enforcer->enforce();

        self::assertTrue($result['vacuum_run']);
    }

    #[Test]
    public function enforceReturnsZeroWhenNoEventsToDelete(): void
    {
        $recentTimestamp = $this->nowUs();
        $this->insertEvent($recentTimestamp);

        $policy = new RetentionPolicy(maxAgeDays: 7, maxSizeMb: 500);

        // Set recent vacuum so vacuum doesn't run
        $this->store->setMeta('last_vacuum', (string) time());

        $enforcer = new RetentionEnforcer($this->store, $policy);

        $result = $enforcer->enforce();

        self::assertSame(0, $result['events_deleted']);
        self::assertFalse($result['vacuum_run']);
    }

    #[Test]
    public function enforceTracksPrunedChainLinks(): void
    {
        $oldTimestamp = $this->nowUs() - (8 * 86400 * 1_000_000);
        $this->insertEvent($oldTimestamp);

        $policy = new RetentionPolicy(maxAgeDays: 7, maxSizeMb: 500);
        $this->store->setMeta('last_vacuum', (string) time());

        $enforcer = new RetentionEnforcer($this->store, $policy);
        $enforcer->enforce();

        $linksPruned = $this->store->getMeta('links_pruned');
        self::assertNotNull($linksPruned);
        self::assertGreaterThanOrEqual(1, (int) $linksPruned);
    }

    #[Test]
    public function enforceRecordsMetricsWhenRegistryProvided(): void
    {
        $registry = new MetricRegistry();

        $oldTimestamp = $this->nowUs() - (8 * 86400 * 1_000_000);
        $this->insertEvent($oldTimestamp);

        $policy = new RetentionPolicy(maxAgeDays: 7, maxSizeMb: 500);
        $this->store->setMeta('last_vacuum', (string) time());

        $enforcer = new RetentionEnforcer($this->store, $policy, $registry);
        $enforcer->enforce();

        $counter = $registry->counter('studio.retention.events_deleted');
        self::assertGreaterThan(0.0, $counter->value());
    }

    #[Test]
    public function enforceHandlesEmptyStore(): void
    {
        $policy = new RetentionPolicy(maxAgeDays: 7, maxSizeMb: 500);
        $this->store->setMeta('last_vacuum', (string) time());

        $enforcer = new RetentionEnforcer($this->store, $policy);
        $result = $enforcer->enforce();

        self::assertSame(0, $result['events_deleted']);
        self::assertFalse($result['vacuum_run']);
    }
}
