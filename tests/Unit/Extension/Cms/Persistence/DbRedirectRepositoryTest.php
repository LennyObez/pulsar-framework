<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Internal\Persistence\DbRedirectRepository;

#[CoversClass(DbRedirectRepository::class)]
final class DbRedirectRepositoryTest extends TestCase
{
    #[Test]
    public function findByPathReturnsNullWhenNotFound(): void
    {
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(new Result([]));

        $repo = new DbRedirectRepository($db, null);

        self::assertNull($repo->findByPath('/old-path'));
    }

    #[Test]
    public function findByPathReturnsHydratedRedirect(): void
    {
        $row = $this->createRedirectRow([
            'id' => 'redir-1',
            'tenant_id' => null,
            'from_path' => '/old-page',
            'to_path' => '/new-page',
            'status_code' => 301,
            'locale' => 'en',
            'hits' => 42,
            'last_hit_at' => '2024-06-15T10:00:00+00:00',
            'created_at' => '2024-01-01T00:00:00+00:00',
            'created_by' => 'user-1',
            'reason' => 'Slug changed',
            'deleted_at' => null,
        ]);

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(new Result([$row]));

        $repo = new DbRedirectRepository($db, null);
        $redirect = $repo->findByPath('/old-page', 'en');

        self::assertNotNull($redirect);
        self::assertSame('redir-1', $redirect->id);
        self::assertSame('/old-page', $redirect->fromPath);
        self::assertSame('/new-page', $redirect->toPath);
        self::assertSame(301, $redirect->statusCode);
        self::assertSame('en', $redirect->locale);
        self::assertSame(42, $redirect->hits);
        self::assertNotNull($redirect->lastHitAt);
        self::assertSame('Slug changed', $redirect->reason);
        self::assertNull($redirect->deletedAt);
    }

    #[Test]
    public function findByPathUsesTenantIdFromConstructor(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::anything(),
                self::callback(static fn(array $b): bool => $b['tenant_key'] === 'tenant-abc'),
            )
            ->willReturn(new Result([]));

        $repo = new DbRedirectRepository($db, 'tenant-abc');
        $repo->findByPath('/test');
    }

    #[Test]
    public function findByPathUsesExplicitTenantOverride(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::anything(),
                self::callback(static fn(array $b): bool => $b['tenant_key'] === 'override'),
            )
            ->willReturn(new Result([]));

        $repo = new DbRedirectRepository($db, 'default');
        $repo->findByPath('/test', tenantId: 'override');
    }

    #[Test]
    public function findByPathUsesSentinelWhenNoTenant(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::anything(),
                self::callback(static fn(array $b): bool => $b['tenant_key'] === '00000000-0000-0000-0000-000000000000'),
            )
            ->willReturn(new Result([]));

        $repo = new DbRedirectRepository($db, null);
        $repo->findByPath('/test');
    }

    #[Test]
    public function saveCallsExecuteWithCorrectBindings(): void
    {
        $now = new DateTimeImmutable('2024-06-15T10:00:00+00:00');
        $redirect = new Redirect(
            id: 'redir-save',
            tenantId: 'tenant-1',
            fromPath: '/old',
            toPath: '/new',
            statusCode: 308,
            locale: 'de',
            hits: 0,
            lastHitAt: null,
            createdAt: $now,
            createdBy: 'user-1',
            reason: 'Manual redirect',
        );

        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO `cms_redirects`'),
                self::callback(static function (array $b): bool {
                    return $b['id'] === 'redir-save'
                        && $b['from_path'] === '/old'
                        && $b['to_path'] === '/new'
                        && $b['status_code'] === 308
                        && $b['locale'] === 'de'
                        && $b['hits'] === 0
                        && $b['last_hit_at'] === null
                        && $b['reason'] === 'Manual redirect';
                }),
            );

        $repo = new DbRedirectRepository($db, null);
        $repo->save($redirect);
    }

    #[Test]
    public function saveGeneratesPostgresqlSyntax(): void
    {
        $now = new DateTimeImmutable('2024-06-15T10:00:00+00:00');
        $redirect = new Redirect(
            id: 'redir-pg',
            tenantId: null,
            fromPath: '/pg-old',
            toPath: '/pg-new',
            statusCode: 301,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: $now,
            createdBy: 'user-pg',
            reason: 'PostgreSQL test',
        );

        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::PostgreSQL);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::logicalAnd(
                    self::stringContains('INSERT INTO "cms_redirects"'),
                    self::stringContains('ON CONFLICT'),
                    self::stringContains('DO UPDATE SET'),
                    self::stringContains('EXCLUDED.'),
                ),
                self::callback(static fn(array $b): bool => $b['id'] === 'redir-pg'
                    && $b['from_path'] === '/pg-old'),
            );

        $repo = new DbRedirectRepository($db, null);
        $repo->save($redirect);
    }

    #[Test]
    public function saveGeneratesSqliteSyntax(): void
    {
        $now = new DateTimeImmutable('2024-06-15T10:00:00+00:00');
        $redirect = new Redirect(
            id: 'redir-sl',
            tenantId: null,
            fromPath: '/sl-old',
            toPath: '/sl-new',
            statusCode: 308,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: $now,
            createdBy: 'user-sl',
            reason: 'SQLite test',
        );

        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::SQLite);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::logicalAnd(
                    self::stringContains('INSERT INTO "cms_redirects"'),
                    self::stringContains('ON CONFLICT'),
                    self::stringContains('DO UPDATE SET'),
                ),
                self::anything(),
            );

        $repo = new DbRedirectRepository($db, null);
        $repo->save($redirect);
    }

    #[Test]
    public function incrementHitsCallsExecute(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('hits = hits + 1'),
                self::callback(static fn(array $b): bool => $b['id'] === 'redir-1' && isset($b['last_hit_at'])),
            );

        $repo = new DbRedirectRepository($db, null);
        $repo->incrementHits('redir-1');
    }

    #[Test]
    public function findAllReturnsListOfRedirects(): void
    {
        $rows = [
            $this->createRedirectRow([
                'id' => 'redir-a',
                'tenant_id' => null,
                'from_path' => '/a',
                'to_path' => '/b',
                'status_code' => 301,
                'locale' => null,
                'hits' => 10,
                'last_hit_at' => null,
                'created_at' => '2024-06-15T10:00:00+00:00',
                'created_by' => 'user-1',
                'reason' => 'test',
                'deleted_at' => null,
            ]),
            $this->createRedirectRow([
                'id' => 'redir-b',
                'tenant_id' => null,
                'from_path' => '/c',
                'to_path' => '/d',
                'status_code' => 308,
                'locale' => 'en',
                'hits' => 0,
                'last_hit_at' => null,
                'created_at' => '2024-06-14T10:00:00+00:00',
                'created_by' => 'user-2',
                'reason' => 'test2',
                'deleted_at' => null,
            ]),
        ];

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(new Result($rows));

        $repo = new DbRedirectRepository($db, null);
        $results = $repo->findAll();

        self::assertCount(2, $results);
        self::assertSame('redir-a', $results[0]->id);
        self::assertSame('redir-b', $results[1]->id);
    }

    #[Test]
    public function findAllCalculatesOffsetFromPage(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::anything(),
                self::callback(static fn(array $b): bool => $b['offset'] === 100 && $b['limit'] === 50),
            )
            ->willReturn(new Result([]));

        $repo = new DbRedirectRepository($db, null);
        $repo->findAll(page: 3, perPage: 50);
    }

    #[Test]
    public function findAllClampsPageToMinimumOne(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('query')
            ->with(
                self::anything(),
                self::callback(static fn(array $b): bool => $b['offset'] === 0),
            )
            ->willReturn(new Result([]));

        $repo = new DbRedirectRepository($db, null);
        $repo->findAll(page: 0);
    }

    #[Test]
    public function deleteCallsExecuteWithSoftDelete(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('deleted_at'),
                self::callback(static fn(array $b): bool => $b['id'] === 'redir-del' && isset($b['deleted_at'])),
            );

        $repo = new DbRedirectRepository($db, null);
        $repo->delete('redir-del');
    }

    #[Test]
    public function purgeDeletedCallsExecuteWithCutoff(): void
    {
        $cutoff = new DateTimeImmutable('2024-01-01T00:00:00+00:00');

        $db = $this->createMock(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('DELETE FROM cms_redirects'),
                self::callback(static fn(array $b): bool => $b['cutoff'] === $cutoff->format('c')),
            )
            ->willReturn(5);

        $repo = new DbRedirectRepository($db, null);
        $count = $repo->purgeDeleted($cutoff);

        self::assertSame(5, $count);
    }

    #[Test]
    public function hydrateHandlesNullLastHitAt(): void
    {
        $row = $this->createRedirectRow([
            'id' => 'redir-null-hit',
            'tenant_id' => null,
            'from_path' => '/x',
            'to_path' => '/y',
            'status_code' => 301,
            'locale' => null,
            'hits' => 0,
            'last_hit_at' => null,
            'created_at' => '2024-01-01T00:00:00+00:00',
            'created_by' => 'user-1',
            'reason' => 'test',
            'deleted_at' => null,
        ]);

        $db = $this->createStub(ConnectionInterface::class);
        $db->method('driver')->willReturn(Driver::MySQL);
        $db->method('query')->willReturn(new Result([$row]));

        $repo = new DbRedirectRepository($db, null);
        $redirect = $repo->findByPath('/x');

        self::assertNotNull($redirect);
        self::assertNull($redirect->lastHitAt);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createRedirectRow(array $data): Row
    {
        return new Row($data);
    }
}
