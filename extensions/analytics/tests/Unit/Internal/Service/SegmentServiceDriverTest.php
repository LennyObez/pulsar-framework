<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Domain\Segment;
use Pulsar\Extension\Analytics\Internal\Service\SegmentService;

final class SegmentServiceDriverTest extends TestCase
{
    /**
     * @return iterable<string, array{Driver, string}>
     */
    public static function driverLikeSyntaxProvider(): iterable
    {
        yield 'MySQL uses CONCAT' => [Driver::MySQL, 'CONCAT'];
        yield 'PostgreSQL uses CONCAT' => [Driver::PostgreSQL, 'CONCAT'];
        yield 'SQLite uses || operator' => [Driver::SQLite, '||'];
    }

    #[Test]
    #[DataProvider('driverLikeSyntaxProvider')]
    public function countVisitorsUsesDriverSpecificLikeSyntax(Driver $driver, string $expectedSyntax): void
    {
        $segmentId = 'seg_001';
        $segmentRow = $this->createStub(Row::class);
        $segmentRow->method('getString')->willReturnCallback(static function (string $key) use ($segmentId): string {
            return match ($key) {
                'id' => $segmentId,
                'site_id' => 'site_1',
                'name' => 'Organic',
                'filters' => json_encode([
                    ['dimension' => 'referrer_source', 'operator' => 'contains', 'value' => 'google'],
                ]),
                'created_at' => '2026-01-01 00:00:00',
                default => '',
            };
        });

        $countRow = $this->createStub(Row::class);
        $countRow->method('getInt')->willReturn(42);

        $queryCounter = 0;
        $capturedSql = '';

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn($driver);
        $connection->method('query')->willReturnCallback(
            static function (string $sql) use (&$queryCounter, &$capturedSql, $segmentRow, $countRow): Result {
                $queryCounter++;
                if ($queryCounter === 1) {
                    // Segment lookup
                    return new Result([$segmentRow]);
                }
                // Count query
                $capturedSql = $sql;

                return new Result([$countRow]);
            },
        );

        $service = new SegmentService($connection);

        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-01-31');

        $count = $service->countVisitors($segmentId, $from, $to);

        self::assertSame(42, $count);
        self::assertStringContainsString($expectedSyntax, $capturedSql);
        self::assertStringContainsString('LIKE', $capturedSql);
    }

    #[Test]
    public function countVisitorsSqliteDoesNotUseConcatFunction(): void
    {
        $segmentId = 'seg_002';
        $segmentRow = $this->createStub(Row::class);
        $segmentRow->method('getString')->willReturnCallback(static function (string $key) use ($segmentId): string {
            return match ($key) {
                'id' => $segmentId,
                'site_id' => 'site_1',
                'name' => 'Test',
                'filters' => json_encode([
                    ['dimension' => 'utm_source', 'operator' => 'starts_with', 'value' => 'fb'],
                ]),
                'created_at' => '2026-01-01 00:00:00',
                default => '',
            };
        });

        $countRow = $this->createStub(Row::class);
        $countRow->method('getInt')->willReturn(10);

        $queryCounter = 0;
        $capturedSql = '';

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturnCallback(
            static function (string $sql) use (&$queryCounter, &$capturedSql, $segmentRow, $countRow): Result {
                $queryCounter++;
                if ($queryCounter === 1) {
                    return new Result([$segmentRow]);
                }
                $capturedSql = $sql;

                return new Result([$countRow]);
            },
        );

        $service = new SegmentService($connection);

        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-01-31');

        $service->countVisitors($segmentId, $from, $to);

        // SQLite must not use CONCAT()
        self::assertStringNotContainsString('CONCAT', $capturedSql);
        self::assertStringContainsString('||', $capturedSql);
    }

    #[Test]
    public function countVisitorsEqualsOperatorDoesNotUseLike(): void
    {
        $segmentId = 'seg_003';
        $segmentRow = $this->createStub(Row::class);
        $segmentRow->method('getString')->willReturnCallback(static function (string $key) use ($segmentId): string {
            return match ($key) {
                'id' => $segmentId,
                'site_id' => 'site_1',
                'name' => 'USA',
                'filters' => json_encode([
                    ['dimension' => 'country', 'operator' => 'eq', 'value' => 'US'],
                ]),
                'created_at' => '2026-01-01 00:00:00',
                default => '',
            };
        });

        $countRow = $this->createStub(Row::class);
        $countRow->method('getInt')->willReturn(100);

        $queryCounter = 0;
        $capturedSql = '';

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);
        $connection->method('query')->willReturnCallback(
            static function (string $sql) use (&$queryCounter, &$capturedSql, $segmentRow, $countRow): Result {
                $queryCounter++;
                if ($queryCounter === 1) {
                    return new Result([$segmentRow]);
                }
                $capturedSql = $sql;

                return new Result([$countRow]);
            },
        );

        $service = new SegmentService($connection);

        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-01-31');

        $service->countVisitors($segmentId, $from, $to);

        self::assertStringNotContainsString('LIKE', $capturedSql);
        self::assertStringContainsString('=', $capturedSql);
    }
}
