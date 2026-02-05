<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Retention;

use function bin2hex;
use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\EventEnvelope;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;
use Pulsar\Studio\Console\Retention\RetentionEnforcer;
use Pulsar\Studio\Console\Retention\RetentionPolicy;
use Pulsar\Studio\Console\Storage\SqliteEventStore;

use function random_bytes;

#[CoversClass(RetentionEnforcer::class)]
final class RetentionEnforcerTest extends TestCase
{
    private ?SqliteEventStore $store = null;

    protected function setUp(): void
    {
        $this->store = SqliteEventStore::inMemory();
    }

    private function getStore(): SqliteEventStore
    {
        if ($this->store === null) {
            $this->store = SqliteEventStore::inMemory();
        }

        return $this->store;
    }

    #[Test]
    public function enforceDeletesEventsOlderThanMaxAge(): void
    {
        $store = $this->getStore();
        // Create a policy with 1 day max age
        $policy = new RetentionPolicy(maxAgeDays: 1);
        $enforcer = new RetentionEnforcer($store, $policy);

        // Insert an old event (2 days ago in microseconds)
        $oldTimestamp = (int) ((microtime(true) - (float) (2 * 86400)) * 1_000_000.0);
        $this->insertEvent($oldTimestamp);

        // Insert a recent event (now)
        $recentTimestamp = (int) (microtime(true) * 1_000_000.0);
        $this->insertEvent($recentTimestamp);

        $result = $enforcer->enforce();

        self::assertSame(1, $result['events_deleted']);
        self::assertSame(1, $store->count());
    }

    #[Test]
    public function enforcePreservesRecentEvents(): void
    {
        $store = $this->getStore();
        $policy = new RetentionPolicy(maxAgeDays: 7);
        $enforcer = new RetentionEnforcer($store, $policy);

        // Insert events from 1, 2, and 3 days ago (all within retention)
        $this->insertEvent((int) ((microtime(true) - (float) (1 * 86400)) * 1_000_000.0));
        $this->insertEvent((int) ((microtime(true) - (float) (2 * 86400)) * 1_000_000.0));
        $this->insertEvent((int) ((microtime(true) - (float) (3 * 86400)) * 1_000_000.0));

        $result = $enforcer->enforce();

        self::assertSame(0, $result['events_deleted']);
        self::assertSame(3, $store->count());
    }

    #[Test]
    public function enforceWhenNoEventsExist(): void
    {
        $store = $this->getStore();
        $policy = new RetentionPolicy(maxAgeDays: 7);
        $enforcer = new RetentionEnforcer($store, $policy);

        $result = $enforcer->enforce();

        self::assertSame(0, $result['events_deleted']);
        self::assertTrue($result['vacuum_run']);
    }

    #[Test]
    public function enforceRunsVacuumOnFirstRun(): void
    {
        $store = $this->getStore();
        $policy = new RetentionPolicy(vacuumIntervalHours: 24);
        $enforcer = new RetentionEnforcer($store, $policy);

        $result = $enforcer->enforce();

        self::assertTrue($result['vacuum_run']);
        self::assertNotNull($store->getMeta('last_vacuum'));
    }

    #[Test]
    public function enforceSkipsVacuumIfRecentlyRun(): void
    {
        $store = $this->getStore();
        $policy = new RetentionPolicy(vacuumIntervalHours: 24);
        $enforcer = new RetentionEnforcer($store, $policy);

        // Set last vacuum to now
        $store->setMeta('last_vacuum', (string) time());

        $result = $enforcer->enforce();

        self::assertFalse($result['vacuum_run']);
    }

    #[Test]
    public function enforceRunsVacuumAfterIntervalElapsed(): void
    {
        $store = $this->getStore();
        $policy = new RetentionPolicy(vacuumIntervalHours: 1);
        $enforcer = new RetentionEnforcer($store, $policy);

        // Set last vacuum to 2 hours ago
        $store->setMeta('last_vacuum', (string) (time() - 7200));

        $result = $enforcer->enforce();

        self::assertTrue($result['vacuum_run']);
    }

    #[Test]
    public function enforceDeletesMultipleOldEvents(): void
    {
        $store = $this->getStore();
        $policy = new RetentionPolicy(maxAgeDays: 1);
        $enforcer = new RetentionEnforcer($store, $policy);

        // Insert 5 old events (all 2 days ago)
        for ($i = 0; $i < 5; $i++) {
            $oldTimestamp = (int) ((microtime(true) - (float) (2 * 86400)) * 1_000_000.0) + $i;
            $this->insertEvent($oldTimestamp);
        }

        // Insert 3 recent events
        for ($i = 0; $i < 3; $i++) {
            $recentTimestamp = (int) (microtime(true) * 1_000_000.0) + $i;
            $this->insertEvent($recentTimestamp);
        }

        $result = $enforcer->enforce();

        self::assertSame(5, $result['events_deleted']);
        self::assertSame(3, $store->count());
    }

    #[Test]
    public function enforceTracksChainLinksPruned(): void
    {
        $store = $this->getStore();
        $policy = new RetentionPolicy(maxAgeDays: 1);
        $enforcer = new RetentionEnforcer($store, $policy);

        // Insert an old event with chain link
        $oldTimestamp = (int) ((microtime(true) - (float) (2 * 86400)) * 1_000_000.0);
        $this->insertEventWithChain($oldTimestamp);

        $result = $enforcer->enforce();

        self::assertSame(1, $result['events_deleted']);
        // Chain links should be tracked in metadata
        $linksPruned = $store->getMeta('links_pruned');
        self::assertNotNull($linksPruned);
        self::assertSame('1', $linksPruned);
    }

    #[Test]
    public function enforceWithEdgeCaseTimestamp(): void
    {
        $store = $this->getStore();
        // Test with an event exactly at the cutoff boundary
        $policy = new RetentionPolicy(maxAgeDays: 1);
        $enforcer = new RetentionEnforcer($store, $policy);

        // Insert an event slightly older than the cutoff (should be deleted)
        $slightlyOld = (int) ((microtime(true) - 86400.0 - 60.0) * 1_000_000.0);
        $this->insertEvent($slightlyOld);

        // Insert an event slightly newer than the cutoff (should be kept)
        $slightlyNew = (int) ((microtime(true) - 86400.0 + 60.0) * 1_000_000.0);
        $this->insertEvent($slightlyNew);

        $result = $enforcer->enforce();

        self::assertSame(1, $result['events_deleted']);
        self::assertSame(1, $store->count());
    }

    #[Test]
    public function enforceReturnsCorrectResultStructure(): void
    {
        $store = $this->getStore();
        $policy = new RetentionPolicy();
        $enforcer = new RetentionEnforcer($store, $policy);

        $result = $enforcer->enforce();

        self::assertArrayHasKey('events_deleted', $result);
        self::assertArrayHasKey('vacuum_run', $result);
        self::assertIsInt($result['events_deleted']);
        self::assertIsBool($result['vacuum_run']);
    }

    /**
     * Helper to insert a test event at a specific timestamp.
     */
    private function insertEvent(int $timestampUs): void
    {
        $store = $this->getStore();
        $eventId = bin2hex(random_bytes(16));
        $payload = ['test' => 'data'];
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $payloadHash = hash('sha256', $payloadJson);

        $envelope = new EventEnvelope(
            eventId: $eventId,
            eventType: EventType::LogEntry,
            schemaVersion: EventVersion::V1,
            timestampUs: $timestampUs,
            requestId: null,
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'test',
            hostname: 'localhost',
            payload: $payload,
            payloadHash: $payloadHash,
        );

        $store->store($envelope, $payloadJson);
    }

    /**
     * Helper to insert a test event with chain link at a specific timestamp.
     */
    private function insertEventWithChain(int $timestampUs): void
    {
        $store = $this->getStore();
        $eventId = bin2hex(random_bytes(16));
        $payload = ['test' => 'data'];
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $payloadHash = hash('sha256', $payloadJson);

        $envelope = new EventEnvelope(
            eventId: $eventId,
            eventType: EventType::LogEntry,
            schemaVersion: EventVersion::V1,
            timestampUs: $timestampUs,
            requestId: null,
            traceId: null,
            spanId: null,
            jobId: null,
            appEnv: 'test',
            hostname: 'localhost',
            payload: $payload,
            payloadHash: $payloadHash,
        );

        $store->storeWithChain($envelope, $payloadJson, null, null);
    }
}
