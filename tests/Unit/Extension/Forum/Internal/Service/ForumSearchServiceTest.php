<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Internal\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Internal\Service\ForumSearchService;

#[CoversClass(ForumSearchService::class)]
final class ForumSearchServiceTest extends TestCase
{
    private ConnectionInterface&Stub $connection;
    private ForumSearchService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::SQLite);

        $this->service = new ForumSearchService($this->connection);
    }

    #[Test]
    public function searchReturnsMatchingResults(): void
    {
        $countRow = new Row(['total' => 1]);
        $countResult = new Result([$countRow]);

        $dataRow = new Row([
            'id' => 'thread-001',
            'title' => 'How to use Pulsar',
            'slug' => 'how-to-use-pulsar',
            'category_id' => 'cat-001',
            'author_id' => 'user-001',
            'type' => 'discussion',
            'status' => 'open',
            'reply_count' => 5,
            'view_count' => 100,
            'vote_score' => 10,
            'solved_post_id' => null,
            'last_activity_at' => '2026-01-01T00:00:00+00:00',
            'created_at' => '2026-01-01T00:00:00+00:00',
        ]);
        $dataResult = new Result([$dataRow]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($countResult, $dataResult);

        $result = $this->service->search('Pulsar');

        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
        self::assertSame('thread-001', $result->items[0]['id']);
        self::assertSame('How to use Pulsar', $result->items[0]['title']);
    }

    #[Test]
    public function emptyQueryReturnsResultsWithoutFullTextSearch(): void
    {
        $countRow = new Row(['total' => 0]);
        $countResult = new Result([$countRow]);
        $dataResult = new Result([]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($countResult, $dataResult);

        $result = $this->service->search('');

        self::assertSame(0, $result->total);
        self::assertCount(0, $result->items);
        self::assertFalse($result->hasMore);
    }

    #[Test]
    public function searchFiltersByCategoryId(): void
    {
        $countRow = new Row(['total' => 2]);
        $countResult = new Result([$countRow]);

        $row1 = self::threadRow('thread-001', 'Title 1');
        $row2 = self::threadRow('thread-002', 'Title 2');
        $dataResult = new Result([$row1, $row2]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($countResult, $dataResult);

        $result = $this->service->search('test', categoryId: 'cat-001');

        self::assertSame(2, $result->total);
        self::assertCount(2, $result->items);
    }

    #[Test]
    public function searchRespectsPagination(): void
    {
        $countRow = new Row(['total' => 50]);
        $countResult = new Result([$countRow]);
        $dataResult = new Result([]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($countResult, $dataResult);

        $result = $this->service->search('test', page: 3, perPage: 10);

        self::assertSame(50, $result->total);
        self::assertSame(3, $result->currentPage);
        self::assertSame(10, $result->perPage);
        self::assertSame(5, $result->lastPage);
    }

    #[Test]
    public function searchClampsPerPageToMaximum(): void
    {
        $countRow = new Row(['total' => 0]);
        $countResult = new Result([$countRow]);
        $dataResult = new Result([]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($countResult, $dataResult);

        $result = $this->service->search('test', perPage: 500);

        self::assertSame(100, $result->perPage);
    }

    #[Test]
    public function searchClampsPageToMinimum(): void
    {
        $countRow = new Row(['total' => 0]);
        $countResult = new Result([$countRow]);
        $dataResult = new Result([]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($countResult, $dataResult);

        $result = $this->service->search('test', page: -1);

        self::assertSame(1, $result->currentPage);
    }

    #[Test]
    public function searchDetectsSolvedThreads(): void
    {
        $countRow = new Row(['total' => 1]);
        $countResult = new Result([$countRow]);

        $row = new Row([
            'id' => 'thread-001',
            'title' => 'Solved Thread',
            'slug' => 'solved-thread',
            'category_id' => 'cat-001',
            'author_id' => 'user-001',
            'type' => 'question',
            'status' => 'open',
            'reply_count' => 3,
            'view_count' => 50,
            'vote_score' => 5,
            'solved_post_id' => 'post-answer-001',
            'last_activity_at' => '2026-01-01T00:00:00+00:00',
            'created_at' => '2026-01-01T00:00:00+00:00',
        ]);
        $dataResult = new Result([$row]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($countResult, $dataResult);

        $result = $this->service->search('test');

        self::assertTrue($result->items[0]['is_solved']);
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function provideHasMoreScenarios(): iterable
    {
        yield 'page 1 of 3 has more' => [30, 1, true];
        yield 'page 3 of 3 has no more' => [30, 3, false];
        yield 'single page has no more' => [5, 1, false];
    }

    #[Test]
    #[DataProvider('provideHasMoreScenarios')]
    public function hasMoreIsComputedCorrectly(int $total, int $page, bool $expectedHasMore): void
    {
        $countRow = new Row(['total' => $total]);
        $countResult = new Result([$countRow]);
        $dataResult = new Result([]);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($countResult, $dataResult);

        $result = $this->service->search('test', page: $page, perPage: 10);

        self::assertSame($expectedHasMore, $result->hasMore);
    }

    private static function threadRow(string $id, string $title): Row
    {
        return new Row([
            'id' => $id,
            'title' => $title,
            'slug' => 'slug-' . $id,
            'category_id' => 'cat-001',
            'author_id' => 'user-001',
            'type' => 'discussion',
            'status' => 'open',
            'reply_count' => 0,
            'view_count' => 0,
            'vote_score' => 0,
            'solved_post_id' => null,
            'last_activity_at' => '2026-01-01T00:00:00+00:00',
            'created_at' => '2026-01-01T00:00:00+00:00',
        ]);
    }
}
