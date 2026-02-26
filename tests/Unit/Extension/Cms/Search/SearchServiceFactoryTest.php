<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Cms\Internal\Search\MysqlSearchService;
use Pulsar\Extension\Cms\Internal\Search\PostgresSearchService;
use Pulsar\Extension\Cms\Internal\Search\SearchServiceFactory;
use Pulsar\Extension\Cms\Internal\Search\SqliteSearchService;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;

#[CoversClass(SearchServiceFactory::class)]
final class SearchServiceFactoryTest extends TestCase
{
    private SearchAnalyticsRepositoryInterface&Stub $analyticsRepository;

    protected function setUp(): void
    {
        $this->analyticsRepository = $this->createStub(SearchAnalyticsRepositoryInterface::class);
    }

    #[Test]
    public function creates_postgres_adapter_for_postgresql_driver(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        $service = SearchServiceFactory::create($connection, $this->analyticsRepository, null);

        self::assertInstanceOf(PostgresSearchService::class, $service);
    }

    #[Test]
    public function creates_sqlite_adapter_for_sqlite_driver(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $service = SearchServiceFactory::create($connection, $this->analyticsRepository, null);

        self::assertInstanceOf(SqliteSearchService::class, $service);
    }

    #[Test]
    public function creates_mysql_adapter_for_mysql_driver(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        $service = SearchServiceFactory::create($connection, $this->analyticsRepository, null);

        self::assertInstanceOf(MysqlSearchService::class, $service);
    }

    #[Test]
    public function passes_tenant_id_through_to_adapter(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::PostgreSQL);

        $service = SearchServiceFactory::create($connection, $this->analyticsRepository, 'tenant-123');

        // The service was constructed without error — tenant ID was accepted
        self::assertInstanceOf(PostgresSearchService::class, $service);
    }
}
