<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class DsaRequirements implements HasAuditRequirements, HasDataRetention
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 1825; // 5 years: content moderation and transparency records
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true; // Art. 15, Art. 24, Art. 42: transparency reporting integrity
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 1825;
    }
}
