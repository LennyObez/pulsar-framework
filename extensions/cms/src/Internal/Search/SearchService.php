<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Search;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;

/**
 * @deprecated Use PostgresSearchService directly. This alias exists for backward compatibility
 *             during the transition to multi-database search adapters.
 *
 * @psalm-api Backward-compatible alias produced by SearchServiceFactory;
 *            constructed by the factory, not instantiated by name.
 */
#[Internal(reason: 'Deprecated; use SearchServiceFactory to obtain the correct adapter')]
final readonly class SearchService extends PostgresSearchService
{
    public function __construct(
        ConnectionInterface $connection,
        SearchAnalyticsRepositoryInterface $analyticsRepository,
        ?string $tenantId,
    ) {
        parent::__construct($connection, $analyticsRepository, $tenantId);
    }
}
