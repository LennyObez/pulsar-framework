<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Internal\Persistence\DbRedirectRepository;

#[CoversClass(DbRedirectRepository::class)]
final class DbRedirectRepositorySoftDeleteTest extends TestCase
{
    #[Test]
    public function delete_performs_soft_delete_with_timestamp(): void
    {
        /** @var list<array{sql: string, bindings: array<string, mixed>}> $captured */
        $captured = [];

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$captured): int {
                $captured[] = ['sql' => $sql, 'bindings' => $bindings];

                return 1;
            });

        $repository = new DbRedirectRepository($db, null);
        $repository->delete('redirect-1');

        self::assertCount(1, $captured);
        self::assertStringContainsString('UPDATE cms_redirects SET deleted_at', $captured[0]['sql']);
        self::assertSame('redirect-1', $captured[0]['bindings']['id']);
        self::assertArrayHasKey('deleted_at', $captured[0]['bindings']);
        self::assertNotNull($captured[0]['bindings']['deleted_at']);
    }

    #[Test]
    public function find_by_path_excludes_soft_deleted_redirects(): void
    {
        /** @var list<string> $capturedSql */
        $capturedSql = [];

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedSql): Result {
                $capturedSql[] = $sql;

                return new Result([]);
            });

        $repository = new DbRedirectRepository($db, null);
        $repository->findByPath('/old-page');

        self::assertCount(1, $capturedSql);
        self::assertStringContainsString('deleted_at IS NULL', $capturedSql[0]);
    }

    #[Test]
    public function find_all_excludes_soft_deleted_redirects(): void
    {
        /** @var list<string> $capturedSql */
        $capturedSql = [];

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$capturedSql): Result {
                $capturedSql[] = $sql;

                return new Result([]);
            });

        $repository = new DbRedirectRepository($db, null);
        $repository->findAll();

        self::assertCount(1, $capturedSql);
        self::assertStringContainsString('deleted_at IS NULL', $capturedSql[0]);
    }

    #[Test]
    public function purge_deleted_removes_records_older_than_cutoff(): void
    {
        /** @var list<array{sql: string, bindings: array<string, mixed>}> $captured */
        $captured = [];

        $cutoff = new DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $bindings) use (&$captured): int {
                $captured[] = ['sql' => $sql, 'bindings' => $bindings];

                return 3;
            });

        $repository = new DbRedirectRepository($db, null);
        $result = $repository->purgeDeleted($cutoff);

        self::assertSame(3, $result);
        self::assertCount(1, $captured);
        self::assertStringContainsString('DELETE FROM cms_redirects', $captured[0]['sql']);
        self::assertStringContainsString('deleted_at IS NOT NULL', $captured[0]['sql']);
        self::assertStringContainsString('deleted_at <', $captured[0]['sql']);
        self::assertSame($cutoff->format('c'), $captured[0]['bindings']['cutoff']);
    }

    #[Test]
    public function hydrate_includes_deleted_at_field(): void
    {
        $now = new DateTimeImmutable();
        $deletedAt = '2025-06-15T10:30:00+00:00';

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturn(new Result([new Row([
                'id' => 'r1',
                'tenant_id' => null,
                'from_path' => '/old',
                'to_path' => '/new',
                'status_code' => 301,
                'locale' => null,
                'hits' => 5,
                'last_hit_at' => null,
                'created_at' => $now->format('c'),
                'created_by' => 'user-1',
                'reason' => 'Slug changed',
                'deleted_at' => $deletedAt,
            ])]));

        $repository = new DbRedirectRepository($db, null);

        // Use findByPath — even though SQL filters deleted_at IS NULL,
        // the stub returns the row unconditionally so we can test hydration
        $redirect = $repository->findByPath('/old');

        self::assertNotNull($redirect);
        self::assertNotNull($redirect->deletedAt);
        self::assertSame($deletedAt, $redirect->deletedAt->format('c'));
    }

    #[Test]
    public function hydrate_handles_null_deleted_at(): void
    {
        $now = new DateTimeImmutable();

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturn(new Result([new Row([
                'id' => 'r2',
                'tenant_id' => null,
                'from_path' => '/active',
                'to_path' => '/destination',
                'status_code' => 301,
                'locale' => null,
                'hits' => 0,
                'last_hit_at' => null,
                'created_at' => $now->format('c'),
                'created_by' => 'user-1',
                'reason' => 'Renamed page',
                'deleted_at' => null,
            ])]));

        $repository = new DbRedirectRepository($db, null);
        $redirect = $repository->findByPath('/active');

        self::assertNotNull($redirect);
        self::assertNull($redirect->deletedAt);
    }
}
