<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Profiler\ProfileEntry;
use Pulsar\Observability\Profiler\RequestProfile;
use Pulsar\Observability\Profiler\RequestProfiler;

#[CoversClass(RequestProfile::class)]
#[CoversClass(ProfileEntry::class)]
#[CoversClass(RequestProfiler::class)]
final class RequestProfileCoverageTest extends TestCase
{
    #[Test]
    public function cacheHitRateZeroWhenNoCacheActivity(): void
    {
        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 10.0,
            entries: [],
            cacheHits: 0,
            cacheMisses: 0,
        );

        self::assertSame(0.0, $profile->cacheHitRate());
    }

    #[Test]
    public function cacheHitRateHundredPercentAllHits(): void
    {
        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 10.0,
            entries: [],
            cacheHits: 10,
            cacheMisses: 0,
        );

        self::assertSame(100.0, $profile->cacheHitRate());
    }

    #[Test]
    public function cacheHitRateFiftyPercent(): void
    {
        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 10.0,
            entries: [],
            cacheHits: 5,
            cacheMisses: 5,
        );

        self::assertSame(50.0, $profile->cacheHitRate());
    }

    #[Test]
    public function categoryTimeMsSumsEntryDurations(): void
    {
        $now = 1_000_000_000;

        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 100.0,
            entries: [
                new ProfileEntry('database', 'q1', $now, $now + 2_000_000), // 2ms
                new ProfileEntry('middleware', 'auth', $now, $now + 1_000_000), // 1ms
                new ProfileEntry('database', 'q2', $now, $now + 3_000_000), // 3ms
            ],
        );

        self::assertSame(5.0, $profile->categoryTimeMs('database'));
        self::assertSame(1.0, $profile->categoryTimeMs('middleware'));
        self::assertSame(0.0, $profile->categoryTimeMs('view'));
    }

    #[Test]
    public function entriesByCategoryReturnsEmpty(): void
    {
        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 10.0,
            entries: [
                new ProfileEntry('database', 'q1', 0, 100),
            ],
        );

        self::assertSame([], $profile->entriesByCategory('middleware'));
    }

    #[Test]
    public function timelinePreservesAllEntries(): void
    {
        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 10.0,
            entries: [
                new ProfileEntry('c', 'third', 300, 400),
                new ProfileEntry('a', 'first', 100, 200),
                new ProfileEntry('b', 'second', 200, 300),
            ],
        );

        $timeline = $profile->timeline();
        self::assertCount(3, $timeline);
        self::assertSame('first', $timeline[0]->label);
        self::assertSame('second', $timeline[1]->label);
        self::assertSame('third', $timeline[2]->label);
    }

    #[Test]
    public function slowestReturnsLimitedEntries(): void
    {
        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 10.0,
            entries: [
                new ProfileEntry('a', 'fast', 0, 100),
                new ProfileEntry('b', 'slow', 0, 10_000_000),
                new ProfileEntry('c', 'medium', 0, 5_000_000),
                new ProfileEntry('d', 'slower', 0, 8_000_000),
            ],
        );

        $slowest = $profile->slowest(2);
        self::assertCount(2, $slowest);
        self::assertSame('slow', $slowest[0]->label);
        self::assertSame('slower', $slowest[1]->label);
    }

    #[Test]
    public function slowestDefaultLimitIsFive(): void
    {
        $entries = [];
        for ($i = 0; $i < 10; $i++) {
            $entries[] = new ProfileEntry('test', "entry-{$i}", 0, ($i + 1) * 100);
        }

        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 100.0,
            entries: $entries,
        );

        $slowest = $profile->slowest();
        self::assertCount(5, $slowest);
    }

    #[Test]
    public function toArrayContainsAllCategoryBreakdowns(): void
    {
        $profile = new RequestProfile(
            method: 'GET',
            path: '/test',
            statusCode: 200,
            totalMs: 10.0,
            entries: [],
            queryCount: 5,
            queryTimeMs: 3.5,
            cacheHits: 3,
            cacheMisses: 1,
            peakMemoryBytes: 1_048_576,
        );

        $array = $profile->toArray();

        self::assertArrayHasKey('categories', $array);
        self::assertIsArray($array['categories']);
        /** @var array<string, mixed> $categories */
        $categories = $array['categories'];
        self::assertArrayHasKey('middleware', $categories);
        self::assertArrayHasKey('routing', $categories);
        self::assertArrayHasKey('controller', $categories);
        self::assertArrayHasKey('database', $categories);
        self::assertArrayHasKey('view', $categories);
        self::assertSame(5, $array['query_count']);
        self::assertSame(3.5, $array['query_time_ms']);
        self::assertSame(1_048_576, $array['peak_memory_bytes']);
        self::assertSame(0, $array['entry_count']);
        self::assertEqualsWithDelta(75.0, $array['cache_hit_rate'], 0.1);
    }

    #[Test]
    public function profilerSqlTruncationWorks(): void
    {
        $profiler = new RequestProfiler();
        $profiler->begin();

        $longSql = str_repeat('SELECT * FROM users WHERE id = 1 ', 20);
        $profiler->recordQuery($longSql, 1.0);

        $profile = $profiler->finish('GET', '/', 200);

        $entry = $profile->entries[0];
        self::assertLessThanOrEqual(203, mb_strlen($entry->label)); // 200 + "..."
    }

    #[Test]
    public function profilerShortSqlNotTruncated(): void
    {
        $profiler = new RequestProfiler();
        $profiler->begin();

        $shortSql = 'SELECT 1';
        $profiler->recordQuery($shortSql, 0.5);

        $profile = $profiler->finish('GET', '/', 200);

        self::assertSame('SELECT 1', $profile->entries[0]->label);
    }

    #[Test]
    public function profilerRecentProfilesEvictsOldest(): void
    {
        $profiler = new RequestProfiler(maxProfiles: 3);

        for ($i = 0; $i < 5; $i++) {
            $profiler->begin();
            $profiler->finish('GET', "/path-{$i}", 200);
        }

        $profiles = $profiler->recentProfiles();
        self::assertCount(3, $profiles);
        self::assertSame('/path-2', $profiles[0]->path);
        self::assertSame('/path-3', $profiles[1]->path);
        self::assertSame('/path-4', $profiles[2]->path);
    }

    #[Test]
    public function profilerBeginResetsState(): void
    {
        $profiler = new RequestProfiler();

        $profiler->begin();
        $timer = $profiler->start('middleware', 'auth');
        $profiler->stop($timer);
        $profiler->recordQuery('SELECT 1', 0.5);
        $profiler->recordCacheHit('key');
        $profiler->recordCacheMiss('key2');

        self::assertSame(2, $profiler->entryCount());

        $profiler->begin();

        self::assertSame(0, $profiler->entryCount());
    }

    #[Test]
    public function profileEntryDurationNsAndMs(): void
    {
        $entry = new ProfileEntry(
            category: 'database',
            label: 'query',
            startNs: 1_000_000_000,
            endNs: 1_005_000_000,
        );

        self::assertSame(5_000_000, $entry->durationNs());
        self::assertSame(5.0, $entry->durationMs());
    }

    #[Test]
    public function profileIsHealthy(): void
    {
        $profile = new RequestProfile(
            method: 'GET',
            path: '/',
            statusCode: 200,
            totalMs: 10.0,
            entries: [],
        );

        self::assertTrue($profile->statusCode >= 200 && $profile->statusCode < 400);
    }
}
