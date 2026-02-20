<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms\Persistence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Internal\Persistence\DbRedirectRepository;

#[CoversClass(DbRedirectRepository::class)]
final class DbRedirectRepositoryTest extends TestCase
{
    private PdoConnection $connection;
    private DbRedirectRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new PdoConnection(
            connectionName: 'test',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $this->connection->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cms_redirects (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                tenant_id VARCHAR(36) DEFAULT NULL,
                from_path VARCHAR(1000) NOT NULL,
                to_path VARCHAR(1000) NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 301,
                locale VARCHAR(10) DEFAULT NULL,
                hits INTEGER NOT NULL DEFAULT 0,
                last_hit_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by VARCHAR(36) NOT NULL,
                reason TEXT NOT NULL DEFAULT '',
                deleted_at TEXT DEFAULT NULL
            )
            SQL);

        $this->repository = new DbRedirectRepository($this->connection, null);
    }

    #[Test]
    public function saveAndFindByPath(): void
    {
        $now = new DateTimeImmutable();
        $redirect = new Redirect(
            id: 'rd-001',
            tenantId: null,
            fromPath: '/old-page',
            toPath: '/new-page',
            statusCode: 301,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: $now,
            createdBy: 'admin-001',
            reason: 'Page moved',
        );
        $this->repository->save($redirect);

        $found = $this->repository->findByPath('/old-page');

        self::assertNotNull($found);
        self::assertSame('rd-001', $found->id);
        self::assertSame('/old-page', $found->fromPath);
        self::assertSame('/new-page', $found->toPath);
        self::assertSame(301, $found->statusCode);
        self::assertSame(0, $found->hits);
        self::assertSame('Page moved', $found->reason);
    }

    #[Test]
    public function findByPathReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByPath('/nonexistent'));
    }

    #[Test]
    public function findByPathWithLocale(): void
    {
        $now = new DateTimeImmutable();
        $redirect = new Redirect(
            id: 'rd-locale',
            tenantId: null,
            fromPath: '/about',
            toPath: '/about-us',
            statusCode: 301,
            locale: 'en',
            hits: 0,
            lastHitAt: null,
            createdAt: $now,
            createdBy: 'admin-001',
            reason: 'Renamed page',
        );
        $this->repository->save($redirect);

        $found = $this->repository->findByPath('/about', 'en');
        self::assertNotNull($found);

        $notFound = $this->repository->findByPath('/about', 'fr');
        self::assertNull($notFound);
    }

    #[Test]
    public function incrementHitsUpdatesCounter(): void
    {
        $now = new DateTimeImmutable();
        $redirect = new Redirect(
            id: 'rd-hits',
            tenantId: null,
            fromPath: '/hit-me',
            toPath: '/target',
            statusCode: 301,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: $now,
            createdBy: 'admin-001',
            reason: 'SEO redirect',
        );
        $this->repository->save($redirect);

        $this->repository->incrementHits('rd-hits');
        $this->repository->incrementHits('rd-hits');
        $this->repository->incrementHits('rd-hits');

        $found = $this->repository->findByPath('/hit-me');
        self::assertNotNull($found);
        self::assertSame(3, $found->hits);
        self::assertNotNull($found->lastHitAt);
    }

    #[Test]
    public function findAllReturnsPaginatedResults(): void
    {
        $now = new DateTimeImmutable();
        for ($i = 1; $i <= 5; $i++) {
            $redirect = new Redirect(
                id: "rd-all-{$i}",
                tenantId: null,
                fromPath: "/old-{$i}",
                toPath: "/new-{$i}",
                statusCode: 301,
                locale: null,
                hits: 0,
                lastHitAt: null,
                createdAt: $now,
                createdBy: 'admin-001',
                reason: "Redirect {$i}",
            );
            $this->repository->save($redirect);
        }

        $results = $this->repository->findAll(page: 1, perPage: 3);

        self::assertCount(3, $results);
    }

    #[Test]
    public function deleteSoftDeletesRedirect(): void
    {
        $now = new DateTimeImmutable();
        $redirect = new Redirect(
            id: 'rd-del',
            tenantId: null,
            fromPath: '/delete-me',
            toPath: '/gone',
            statusCode: 301,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: $now,
            createdBy: 'admin-001',
            reason: 'Cleanup',
        );
        $this->repository->save($redirect);

        $this->repository->delete('rd-del');

        self::assertNull($this->repository->findByPath('/delete-me'));

        $raw = $this->connection->query('SELECT deleted_at FROM cms_redirects WHERE id = :id', ['id' => 'rd-del']);
        self::assertNotNull($raw->first()?->getNullableString('deleted_at'));
    }

    #[Test]
    public function purgeDeletedRemovesOldRecords(): void
    {
        $old = new DateTimeImmutable('2024-01-01');
        $redirect = new Redirect(
            id: 'rd-purge',
            tenantId: null,
            fromPath: '/purge-me',
            toPath: '/gone',
            statusCode: 301,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: $old,
            createdBy: 'admin-001',
            reason: 'To purge',
        );
        $this->repository->save($redirect);

        $this->connection->execute(
            "UPDATE cms_redirects SET deleted_at = :deleted_at WHERE id = :id",
            ['deleted_at' => $old->format('c'), 'id' => 'rd-purge'],
        );

        $purged = $this->repository->purgeDeleted(new DateTimeImmutable('2025-01-01'));

        self::assertSame(1, $purged);

        $raw = $this->connection->query('SELECT * FROM cms_redirects WHERE id = :id', ['id' => 'rd-purge']);
        self::assertTrue($raw->isEmpty());
    }

    #[Test]
    public function saveUpdatesExistingRedirect(): void
    {
        $now = new DateTimeImmutable();
        $redirect = new Redirect(
            id: 'rd-upd',
            tenantId: null,
            fromPath: '/original',
            toPath: '/destination-a',
            statusCode: 301,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: $now,
            createdBy: 'admin-001',
            reason: 'Initial',
        );
        $this->repository->save($redirect);

        $updated = new Redirect(
            id: 'rd-upd',
            tenantId: null,
            fromPath: '/original',
            toPath: '/destination-b',
            statusCode: 308,
            locale: null,
            hits: 0,
            lastHitAt: null,
            createdAt: $now,
            createdBy: 'admin-001',
            reason: 'Updated target',
        );
        $this->repository->save($updated);

        $found = $this->repository->findByPath('/original');
        self::assertNotNull($found);
        self::assertSame('/destination-b', $found->toPath);
        self::assertSame(308, $found->statusCode);
        self::assertSame('Updated target', $found->reason);
    }
}
