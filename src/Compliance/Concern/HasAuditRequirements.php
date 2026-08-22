<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Pulsar\Api\Api;

/**
 * Shared compliance concern for frameworks that mandate audit logging.
 *
 * All supported compliance frameworks require some form of audit trail.
 * This interface normalizes the audit retention requirement so the
 * ComplianceProfileResolver can compute the most restrictive value.
 * @api
 */
#[Api(since: '1.0.0')]
interface HasAuditRequirements
{
    /**
     * Minimum audit log retention period in days.
     *
     * The resolver picks the largest value across all enabled frameworks
     * to satisfy every regulation simultaneously.
     */
    public function auditRetentionDays(): int;

    /**
     * Whether tamper-evident (HMAC-chained) audit logs are required.
     */
    public function requiresTamperEvidentAudit(): bool;
}
