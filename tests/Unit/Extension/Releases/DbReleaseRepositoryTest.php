<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Extension\Releases\Internal\Persistence\DbReleaseRepository;
use Pulsar\Extension\Releases\Release;
use Pulsar\Extension\Releases\ReleasePlatform;

#[CoversClass(DbReleaseRepository::class)]
final class DbReleaseRepositoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function releaseRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'rel-001',
            'version' => '2.0.0',
            'platform' => 'android',
            'release_date' => '2026-01-15T10:00:00+00:00',
            'release_notes' => 'Bug fixes',
            'minimum_os_version' => '13.0',
            'download_url' => null,
            'is_beta' => 0,
            'is_stable' => 1,
            'created_at' => '2026-01-15T10:00:00+00:00',
        ], $overrides);
    }

    // ── save ─────────────────────────────────────────────────────────

    #[Test]
    public function saveCallsExecuteWithUpsertQuery(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->expects($this->once())->method('execute');

        $release = new Release(
            id: 'rel-001',
            version: '2.0.0',
            platform: ReleasePlatform::Android,
            releaseDate: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            releaseNotes: 'Bug fixes',
            minimumOsVersion: '13.0',
            downloadUrl: null,
            isBeta: false,
            isStable: true,
            createdAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
        );

        $repo = new DbReleaseRepository($db);
        $repo->save($release);
    }

    // ── findById ─────────────────────────────────────────────────────

    #[Test]
    public function findByIdReturnsReleaseWhenFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([$this->releaseRow()]));

        $repo = new DbReleaseRepository($db);
        $result = $repo->findById('rel-001');

        self::assertInstanceOf(Release::class, $result);
        self::assertSame('rel-001', $result->id);
        self::assertSame('2.0.0', $result->version);
        self::assertSame(ReleasePlatform::Android, $result->platform);
        self::assertSame('Bug fixes', $result->releaseNotes);
        self::assertSame('13.0', $result->minimumOsVersion);
        self::assertNull($result->downloadUrl);
        self::assertFalse($result->isBeta);
        self::assertTrue($result->isStable);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbReleaseRepository($db);

        self::assertNull($repo->findById('nonexistent'));
    }

    #[Test]
    public function findByIdHydratesAllPopulatedFields(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([
            $this->releaseRow([
                'platform' => 'ios',
                'download_url' => 'https://cdn.example.com/release.apk',
                'is_beta' => 1,
                'is_stable' => 0,
            ]),
        ]));

        $repo = new DbReleaseRepository($db);
        $result = $repo->findById('rel-001');

        self::assertInstanceOf(Release::class, $result);
        self::assertSame(ReleasePlatform::Ios, $result->platform);
        self::assertSame('https://cdn.example.com/release.apk', $result->downloadUrl);
        self::assertTrue($result->isBeta);
        self::assertFalse($result->isStable);
    }

    // ── findLatestStable ─────────────────────────────────────────────

    #[Test]
    public function findLatestStableReturnsReleaseWhenFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([$this->releaseRow()]));

        $repo = new DbReleaseRepository($db);
        $result = $repo->findLatestStable(ReleasePlatform::Android);

        self::assertInstanceOf(Release::class, $result);
        self::assertTrue($result->isStable);
    }

    #[Test]
    public function findLatestStableReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturn(Result::fromArrays([]));

        $repo = new DbReleaseRepository($db);

        self::assertNull($repo->findLatestStable(ReleasePlatform::Ios));
    }

    // ── findAll ──────────────────────────────────────────────────────

    #[Test]
    public function findAllReturnsPaginatedResults(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 2]]),
            Result::fromArrays([
                $this->releaseRow(['id' => 'rel-001']),
                $this->releaseRow(['id' => 'rel-002', 'version' => '2.1.0']),
            ]),
        );

        $repo = new DbReleaseRepository($db);
        $result = $repo->findAll(1, 20);

        self::assertSame(2, $result->total);
        self::assertCount(2, $result->items);
        self::assertSame(1, $result->currentPage);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function findAllWithPlatformFilter(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 1]]),
            Result::fromArrays([$this->releaseRow()]),
        );

        $repo = new DbReleaseRepository($db);
        $result = $repo->findAll(1, 20, platform: ReleasePlatform::Android);

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
    }

    #[Test]
    public function findAllExcludingBeta(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 1]]),
            Result::fromArrays([$this->releaseRow()]),
        );

        $repo = new DbReleaseRepository($db);
        $result = $repo->findAll(1, 20, includeBeta: false);

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
    }

    #[Test]
    public function findAllWithAllFilters(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbReleaseRepository($db);
        $result = $repo->findAll(
            1,
            20,
            platform: ReleasePlatform::Web,
            includeBeta: false,
        );

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    #[Test]
    public function findAllWithMultiplePagesHasMore(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 30]]),
            Result::fromArrays([$this->releaseRow()]),
        );

        $repo = new DbReleaseRepository($db);
        $result = $repo->findAll(1, 10);

        self::assertSame(30, $result->total);
        self::assertTrue($result->hasMore);
        self::assertSame(3, $result->lastPage);
    }

    #[Test]
    public function findAllClampsPerPageToMaximumOfOneHundred(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbReleaseRepository($db);
        $result = $repo->findAll(1, 500);

        self::assertSame(100, $result->perPage);
    }

    #[Test]
    public function findAllClampsPageToMinimumOfOne(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->method('query')->willReturnOnConsecutiveCalls(
            Result::fromArrays([['total' => 0]]),
            Result::fromArrays([]),
        );

        $repo = new DbReleaseRepository($db);
        $result = $repo->findAll(-5, 20);

        self::assertSame(1, $result->currentPage);
    }
}
