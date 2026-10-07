<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Profiler\ProfileEntry;
use Pulsar\Observability\Profiler\RequestProfile;
use Pulsar\Observability\Profiler\RequestProfiler;

#[CoversClass(RequestProfiler::class)]
#[CoversClass(RequestProfile::class)]
#[CoversClass(ProfileEntry::class)]
final class RequestProfilerTest extends TestCase
{
    #[Test]
    public function startAndStopRecordsEntry(): void
    {
        $profiler = new RequestProfiler();
        $profiler->begin();

        $timer = $profiler->start('middleware', 'auth');
        // Simulate work
        $profiler->stop($timer);

        self::assertSame(1, $profiler->entryCount());
    }

    #[Test]
    public function finishProducesCompleteProfile(): void
    {
        $profiler = new RequestProfiler();
        $profiler->begin();

        $timer = $profiler->start('middleware', 'cors');
        $profiler->stop($timer);

        $timer = $profiler->start('controller', 'UserController::index');
        $profiler->stop($timer);

        $profile = $profiler->finish('GET', '/api/users', 200);

        self::assertSame('GET', $profile->method);
        self::assertSame('/api/users', $profile->path);
        self::assertSame(200, $profile->statusCode);
        self::assertGreaterThan(0.0, $profile->totalMs);
        self::assertCount(2, $profile->entries);
    }

    #[Test]
    public function recordQueryTracksCountAndTime(): void
    {
        $profiler = new RequestProfiler();
        $profiler->begin();

        $profiler->recordQuery('SELECT * FROM users', 1.5, 10);
        $profiler->recordQuery('SELECT * FROM posts', 2.3, 25);

        $profile = $profiler->finish('GET', '/api/data', 200);

        self::assertSame(2, $profile->queryCount);
        self::assertEqualsWithDelta(3.8, $profile->queryTimeMs, 0.01);
    }

    #[Test]
    public function recordCacheHitAndMissTracked(): void
    {
        $profiler = new RequestProfiler();
        $profiler->begin();

        $profiler->recordCacheHit('user:42');
        $profiler->recordCacheHit('user:43');
        $profiler->recordCacheMiss('user:44');

        $profile = $profiler->finish('GET', '/api/users', 200);

        self::assertSame(2, $profile->cacheHits);
        self::assertSame(1, $profile->cacheMisses);
        self::assertEqualsWithDelta(66.7, $profile->cacheHitRate(), 0.1);
    }

    #[Test]
    public function disabledProfilerSkipsRecording(): void
    {
        $profiler = new RequestProfiler(enabled: false);
        $profiler->begin();

        $timer = $profiler->start('middleware', 'auth');
        self::assertSame(-1, $timer);

        $profiler->stop($timer);
        $profiler->recordQuery('SELECT 1', 0.1);
        $profiler->recordCacheHit('key');
        $profiler->recordCacheMiss('key');

        self::assertSame(0, $profiler->entryCount());
    }

    #[Test]
    public function toggleEnabledState(): void
    {
        $profiler = new RequestProfiler(enabled: false);
        self::assertFalse($profiler->isEnabled());

        $profiler->setEnabled(true);
        self::assertTrue($profiler->isEnabled());
    }

    #[Test]
    public function maxEntriesPreventsUnboundedGrowth(): void
    {
        $profiler = new RequestProfiler(maxEntries: 3);
        $profiler->begin();

        for ($i = 0; $i < 10; $i++) {
            $timer = $profiler->start('controller', "action_{$i}");
            $profiler->stop($timer);
        }

        self::assertSame(3, $profiler->entryCount());
    }

    #[Test]
    public function finishStopsActiveTimers(): void
    {
        $profiler = new RequestProfiler();
        $profiler->begin();

        // Start but don't stop
        $profiler->start('middleware', 'forgotten');

        $profile = $profiler->finish('GET', '/', 200);

        // The forgotten timer should have been auto-stopped
        self::assertCount(1, $profile->entries);
        self::assertSame('middleware', $profile->entries[0]->category);
    }

    #[Test]
    public function recentProfilesStored(): void
    {
        $profiler = new RequestProfiler(maxProfiles: 2);

        $profiler->begin();
        $profiler->finish('GET', '/a', 200);

        $profiler->begin();
        $profiler->finish('GET', '/b', 200);

        $profiler->begin();
        $profiler->finish('GET', '/c', 200);

        $profiles = $profiler->recentProfiles();
        self::assertCount(2, $profiles);
        self::assertSame('/b', $profiles[0]->path);
        self::assertSame('/c', $profiles[1]->path);
    }

    #[Test]
    public function profileTimelineReturnsSortedEntries(): void
    {
        $now = hrtime(true);

        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 10.0,
            entries: [
                new ProfileEntry('controller', 'action', $now + 200, $now + 300),
                new ProfileEntry('middleware', 'auth', $now, $now + 100),
                new ProfileEntry('view', 'render', $now + 300, $now + 400),
            ],
        );

        $timeline = $profile->timeline();
        self::assertSame('middleware', $timeline[0]->category);
        self::assertSame('controller', $timeline[1]->category);
        self::assertSame('view', $timeline[2]->category);
    }

    #[Test]
    public function profileSlowestReturnsTopEntries(): void
    {
        $now = hrtime(true);

        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 10.0,
            entries: [
                new ProfileEntry('a', 'fast', $now, $now + 100),
                new ProfileEntry('b', 'slow', $now, $now + 1_000_000),
                new ProfileEntry('c', 'medium', $now, $now + 500),
            ],
        );

        $slowest = $profile->slowest(2);
        self::assertCount(2, $slowest);
        self::assertSame('slow', $slowest[0]->label);
    }

    #[Test]
    public function profileEntriesByCategoryFilters(): void
    {
        $now = hrtime(true);

        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 10.0,
            entries: [
                new ProfileEntry('database', 'query1', $now, $now + 100),
                new ProfileEntry('middleware', 'auth', $now, $now + 50),
                new ProfileEntry('database', 'query2', $now, $now + 200),
            ],
        );

        $dbEntries = $profile->entriesByCategory('database');
        self::assertCount(2, $dbEntries);
    }

    #[Test]
    public function profileToArrayProducesCompleteStructure(): void
    {
        $profile = new RequestProfile(
            method: 'POST',
            path: '/api/users',
            statusCode: 201,
            totalMs: 15.5,
            entries: [],
            queryCount: 3,
            queryTimeMs: 5.2,
            cacheHits: 1,
            cacheMisses: 2,
            peakMemoryBytes: 2_097_152,
        );

        $array = $profile->toArray();

        self::assertSame('POST', $array['method']);
        self::assertSame('/api/users', $array['path']);
        self::assertSame(201, $array['status_code']);
        self::assertSame(15.5, $array['total_ms']);
        self::assertSame(3, $array['query_count']);
        self::assertSame(5.2, $array['query_time_ms']);
        self::assertSame(2_097_152, $array['peak_memory_bytes']);
        self::assertArrayHasKey('categories', $array);
        self::assertArrayHasKey('timeline', $array);
    }

    #[Test]
    public function profileEntryToArrayIncludesAllFields(): void
    {
        $now = hrtime(true);
        $entry = new ProfileEntry(
            category: 'database',
            label: 'SELECT * FROM users',
            startNs: $now,
            endNs: $now + 1_500_000,
            metadata: ['rows' => 10],
        );

        $array = $entry->toArray();

        self::assertSame('database', $array['category']);
        self::assertSame('SELECT * FROM users', $array['label']);
        self::assertSame(1.5, $array['duration_ms']);
        self::assertSame(['rows' => 10], $array['metadata']);
    }

    #[Test]
    public function profileEntryDurationCalculations(): void
    {
        $entry = new ProfileEntry(
            category: 'test',
            label: 'op',
            startNs: 1_000_000_000,
            endNs: 1_002_500_000,
        );

        self::assertSame(2_500_000, $entry->durationNs());
        self::assertSame(2.5, $entry->durationMs());
    }

    #[Test]
    public function stopNonExistentTimerIsNoOp(): void
    {
        $profiler = new RequestProfiler();
        $profiler->begin();

        // Should not throw
        $profiler->stop(999);
        self::assertSame(0, $profiler->entryCount());
    }

    #[Test]
    public function startWithMetadataAndExtraMetadataMerged(): void
    {
        $profiler = new RequestProfiler();
        $profiler->begin();

        $timer = $profiler->start('controller', 'action', ['route' => '/test']);
        $profiler->stop($timer, ['status' => 200]);

        $profile = $profiler->finish('GET', '/', 200);

        $entry = $profile->entries[0];
        self::assertSame('/test', $entry->metadata['route']);
        self::assertSame(200, $entry->metadata['status']);
    }
}
