<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class MdrRequirements implements HasAuditRequirements, HasDataRetention
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 3650; // 10 years: device lifecycle documentation
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
