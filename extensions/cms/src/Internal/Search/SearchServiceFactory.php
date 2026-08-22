<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Search;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;
use Pulsar\Extension\Cms\Search\SearchServiceInterface;

/**
 * Creates the appropriate search service adapter based on the database driver.
 *
 * @psalm-api Static factory invoked by name from the CMS service provider to
 *            wire SearchServiceInterface in the DI container.
 */
#[Internal(reason: 'Search adapter factory; use SearchServiceInterface')]
final readonly class SearchServiceFactory
{
    public static function create(
        ConnectionInterface $connection,
        SearchAnalyticsRepositoryInterface $analyticsRepository,
        ?string $tenantId,
    ): SearchServiceInterface {
        return match ($connection->driver()) {
            Driver::SQLite => new SqliteSearchService($connection, $analyticsRepository, $tenantId),
            Driver::MySQL => new MysqlSearchService($connection, $analyticsRepository, $tenantId),
            Driver::PostgreSQL => new PostgresSearchService($connection, $analyticsRepository, $tenantId),
        };
    }
}
