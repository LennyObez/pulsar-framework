<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class DataActRequirements implements HasAuditRequirements, HasDataRetention, HasEncryptionRequirements
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 1825; // 5 years: data access audit trails
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return false;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 1825;
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return false;
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true; // Data sharing security requirements
    }
}
