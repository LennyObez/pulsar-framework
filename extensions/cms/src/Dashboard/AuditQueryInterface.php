<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use Pulsar\Api\Api;
use Pulsar\Security\Audit\AuditEntry;

/**
 * Read-only query interface for retrieving recent audit entries.
 *
 * Used by the dashboard to display recent CMS activity without
 * depending on the write-oriented AuditLoggerInterface.
 *
 * @psalm-api Public binding contract; consumed by dashboard widgets.
 * @api
 */
#[Api(since: '1.0.0')]
interface AuditQueryInterface
{
    /**
     * Retrieve recent audit entries, optionally filtered by action prefix.
     *
     * @param string|null $actionPrefix Filter entries whose action starts with this prefix (e.g., 'cms.')
     *
     * @return list<AuditEntry>
     */
    public function getRecent(int $limit = 10, ?string $actionPrefix = null): array;
}
