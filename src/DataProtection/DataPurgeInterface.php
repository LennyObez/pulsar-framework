<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use Pulsar\Api\Api;

/**
 * Purges data that has exceeded its retention policy.
 *
 * Implementations are responsible for locating and deleting (or anonymizing)
 * data records that fall outside their category's retention window. Each
 * implementation handles a specific data store or category.
 * @api
 */
#[Api(since: '1.0.0')]
interface DataPurgeInterface
{
    /**
     * Purge expired data for the given retention policy.
     *
     * Implementations should identify all records in their data store that
     * are past the retention window defined by the policy and remove or
     * anonymize them.
     *
     * @return int The number of records purged
     */
    public function purge(RetentionPolicyInterface $policy): int;

    /**
     * Count the number of records that would be purged without deleting them.
     *
     * Useful for dry-run reporting and audit trails before executing a purge.
     *
     * @return int The number of records eligible for purging
     */
    public function countExpired(RetentionPolicyInterface $policy): int;
}
