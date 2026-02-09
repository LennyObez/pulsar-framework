<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Defines a data retention policy for a specific data category.
 *
 * Implementations determine how long data of a given category should be
 * retained and when it becomes eligible for purging. Each policy maps a
 * category identifier (e.g. "audit_logs", "user_sessions") to a retention
 * period and optional legal basis.
 */
#[Api(since: '1.0.0')]
interface RetentionPolicyInterface
{
    /**
     * Get the data category this policy applies to.
     *
     * Categories are application-defined strings (e.g. "audit_logs",
     * "user_sessions", "payment_records").
     */
    public function category(): string;

    /**
     * Get the retention period in days.
     *
     * Data older than this number of days from its creation timestamp is
     * eligible for purging. A value of 0 means no automatic expiry
     * (indefinite retention).
     *
     * @return non-negative-int
     */
    public function retentionDays(): int;

    /**
     * Get the legal or regulatory basis for this retention period.
     *
     * Returns a human-readable justification (e.g. "GDPR Art. 17",
     * "PCI DSS Req. 3.1", "SOX 7-year requirement"). Returns an empty
     * string if no specific basis is configured.
     */
    public function legalBasis(): string;

    /**
     * Determine whether data created at the given timestamp has expired
     * under this policy.
     */
    public function isExpired(DateTimeImmutable $createdAt, DateTimeImmutable $now): bool;
}
