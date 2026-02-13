<?php

declare(strict_types=1);

namespace Pulsar\Queue\Tenant;

use Pulsar\Api\Api;

/**
 * Result of a tenant fan-out dispatch operation, disambiguated from Concurrency\FanOutResult.
 */
#[Api(since: '1.0.0')]
readonly class TenantFanOutResult
{
    /**
     * @param list<string> $failedTenantIds Tenant IDs whose dispatch failed.
     */
    public function __construct(
        public int $dispatched,
        public int $failed,
        public array $failedTenantIds,
    ) {}
}
