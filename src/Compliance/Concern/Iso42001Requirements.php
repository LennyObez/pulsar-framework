<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class Iso42001Requirements implements HasAuditRequirements, HasDataRetention
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 1825;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 1825;
    }
}
