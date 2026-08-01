<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class Iso13485Requirements implements HasAuditRequirements, HasDataRetention
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 3650; // 10 years: quality record retention per §4.2.5
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 3650;
    }
}
