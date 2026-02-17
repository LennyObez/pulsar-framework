<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Profiler\ProfileEntry;
use Pulsar\Observability\Profiler\RequestProfile;

#[CoversClass(RequestProfile::class)]
final class RequestProfileTest extends TestCase
{
    /**
     * @param list<ProfileEntry> $entries
     * @param array{method?: string, path?: string, statusCode?: int, totalMs?: float, queryCount?: int, queryTimeMs?: float, cacheHits?: int, cacheMisses?: int, peakMemoryBytes?: int} $overrides
     */
    private function createProfile(array $entries = [], array $overrides = []): RequestProfile
    {
        return new RequestProfile(
            method: $overrides['method'] ?? 'GET',
            path: $overrides['path'] ?? '/api/users',
            statusCode: $overrides['statusCode'] ?? 200,
            totalMs: $overrides['totalMs'] ?? 45.5,
            entries: $entries,
            queryCount: $overrides['queryCount'] ?? 3,
            queryTimeMs: $overrides['queryTimeMs'] ?? 12.5,
            cacheHits: $overrides['cacheHits'] ?? 5,
            cacheMisses: $overrides['cacheMisses'] ?? 2,
            peakMemoryBytes: $overrides['peakMemoryBytes'] ?? 4194304,
        );
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $profile = $this->createProfile();

        self::assertSame('GET', $profile->method);
        self::assertSame('/api/users', $profile->path);
        self::assertSame(200, $profile->statusCode);
        self::assertSame(45.5, $profile->totalMs);
        self::assertSame(3, $profile->queryCount);
        self::assertSame(12.5, $profile->queryTimeMs);
        self::assertSame(5, $profile->cacheHits);
        self::assertSame(2, $profile->cacheMisses);
        self::assertSame(4194304, $profile->peakMemoryBytes);
    }

    #[Test]
    public function optionalFieldsDefaultToZero(): void
    {
        $profile = new RequestProfile(
            method: 'POST',
            path: '/',
            statusCode: 201,
            totalMs: 10.0,
            entries: [],
        );

        self::assertSame(0, $profile->queryCount);
        self::assertSame(0.0, $profile->queryTimeMs);
        self::assertSame(0, $profile->cacheHits);
        self::assertSame(0, $profile->cacheMisses);
        self::assertSame(0, $profile->peakMemoryBytes);
    }

    #[Test]
    public function entriesByCategoryFiltersCorrectly(): void
    {
        $entries = [
            new ProfileEntry('middleware', 'auth', 0, 1_000_000),
            new ProfileEntry('database', 'query1', 1_000_000, 2_000_000),
            new ProfileEntry('database', 'query2', 2_000_000, 3_000_000),
            new ProfileEntry('view', 'render', 3_000_000, 4_000_000),
        ];

        $profile = $this->createProfile($entries);

        $dbEntries = $profile->entriesByCategory('database');

        self::assertCount(2, $dbEntries);
        self::assertSame('query1', $dbEntries[0]->label);
        self::assertSame('query2', $dbEntries[1]->label);
    }

    #[Test]
    public function entriesByCategoryReturnsEmptyForUnknownCategory(): void
    {
        $profile = $this->createProfile([
            new ProfileEntry('middleware', 'cors', 0, 1_000_000),
        ]);

        self::assertSame([], $profile->entriesByCategory('custom'));
    }

    #[Test]
    public function categoryTimeMsSumsEntryDurations(): void
    {
        $entries = [
            new ProfileEntry('database', 'q1', 0, 2_000_000),         // 2ms
            new ProfileEntry('database', 'q2', 3_000_000, 8_000_000), // 5ms
        ];

        $profile = $this->createProfile($entries);

        self::assertSame(7.0, $profile->categoryTimeMs('database'));
    }

    #[Test]
    public function categoryTimeMsReturnsZeroForEmptyCategory(): void
    {
        $profile = $this->createProfile();

        self::assertSame(0.0, $profile->categoryTimeMs('database'));
    }

    #[Test]
    public function cacheHitRateCalculatesPercentage(): void
    {
        $profile = $this->createProfile(overrides: ['cacheHits' => 7, 'cacheMisses' => 3]);

        self::assertSame(70.0, $profile->cacheHitRate());
    }

    #[Test]
    public function cacheHitRateReturnsZeroWhenNoCacheOperations(): void
    {
        $profile = $this->createProfile(overrides: ['cacheHits' => 0, 'cacheMisses' => 0]);

        self::assertSame(0.0, $profile->cacheHitRate());
    }

    #[Test]
    public function cacheHitRateHundredPercentOnAllHits(): void
    {
        $profile = $this->createProfile(overrides: ['cacheHits' => 10, 'cacheMisses' => 0]);

        self::assertSame(100.0, $profile->cacheHitRate());
    }

    #[Test]
    public function timelineReturnsSortedByStartTime(): void
    {
        $entries = [
            new ProfileEntry('view', 'render', 30_000_000, 40_000_000),
            new ProfileEntry('middleware', 'auth', 0, 5_000_000),
            new ProfileEntry('database', 'query', 10_000_000, 20_000_000),
        ];

        $profile = $this->createProfile($entries);

        $timeline = $profile->timeline();

        self::assertSame('auth', $timeline[0]->label);
        self::assertSame('query', $timeline[1]->label);
        self::assertSame('render', $timeline[2]->label);
    }

    #[Test]
    public function slowestReturnsByDurationDescending(): void
    {
        $entries = [
            new ProfileEntry('fast', 'op1', 0, 1_000_000),       // 1ms
            new ProfileEntry('slow', 'op2', 0, 100_000_000),     // 100ms
            new ProfileEntry('medium', 'op3', 0, 10_000_000),    // 10ms
            new ProfileEntry('slower', 'op4', 0, 50_000_000),    // 50ms
        ];

        $profile = $this->createProfile($entries);

        $slowest = $profile->slowest(2);

        self::assertCount(2, $slowest);
        self::assertSame('op2', $slowest[0]->label); // 100ms
        self::assertSame('op4', $slowest[1]->label); // 50ms
    }

    #[Test]
    public function slowestReturnsAllEntriesWhenLimitExceedsCount(): void
    {
        $entries = [
            new ProfileEntry('a', 'op1', 0, 1_000_000),
            new ProfileEntry('b', 'op2', 0, 2_000_000),
        ];

        $profile = $this->createProfile($entries);

        self::assertCount(2, $profile->slowest(10));
    }

    #[Test]
    public function toArraySerializesProfile(): void
    {
        $entries = [
            new ProfileEntry('middleware', 'cors', 0, 1_000_000),
            new ProfileEntry('routing', 'match', 1_000_000, 2_000_000),
        ];

        $profile = $this->createProfile($entries, [
            'method' => 'POST',
            'path' => '/api/orders',
            'statusCode' => 201,
            'totalMs' => 15.123,
            'queryCount' => 2,
            'queryTimeMs' => 5.678,
            'cacheHits' => 3,
            'cacheMisses' => 1,
            'peakMemoryBytes' => 2097152,
        ]);

        $array = $profile->toArray();

        self::assertSame('POST', $array['method']);
        self::assertSame('/api/orders', $array['path']);
        self::assertSame(201, $array['status_code']);
        self::assertSame(15.123, $array['total_ms']);
        self::assertSame(2, $array['query_count']);
        self::assertSame(5.678, $array['query_time_ms']);
        self::assertSame(3, $array['cache_hits']);
        self::assertSame(1, $array['cache_misses']);
        self::assertSame(75.0, $array['cache_hit_rate']);
        self::assertSame(2097152, $array['peak_memory_bytes']);
        self::assertSame(2, $array['entry_count']);
        self::assertArrayHasKey('categories', $array);
        self::assertArrayHasKey('timeline', $array);
    }

    #[Test]
    public function toArrayCategoriesIncludeStandardCategories(): void
    {
        $profile = $this->createProfile();

        $array = $profile->toArray();
        self::assertArrayHasKey('categories', $array);

        $categories = $array['categories'];
        self::assertIsArray($categories);

        self::assertArrayHasKey('middleware', $categories);
        self::assertArrayHasKey('routing', $categories);
        self::assertArrayHasKey('controller', $categories);
        self::assertArrayHasKey('database', $categories);
        self::assertArrayHasKey('view', $categories);
    }
}
