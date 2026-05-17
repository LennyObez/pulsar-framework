<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Pulsar\Api\Api;

/**
 * Shared compliance concern for frameworks that mandate data retention policies.
 *
 * Used by PCI-DSS, GDPR, HIPAA, SOC 2, and others.
 * @api
 */
#[Api(since: '1.0.0')]
interface HasDataRetention
{
    /**
     * Minimum data retention period in days.
     *
     * The resolver picks the largest value across all enabled frameworks.
     */
    public function dataRetentionDays(): int;
}
