<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class Hl7FhirRequirements implements HasAuditRequirements, HasConsentManagement, HasDataRetention
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 2190;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return false;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 2190;
    }

    #[Override]
    public function requiresExplicitConsent(): bool
    {
        return true;
    }

    #[Override]
    public function requiresConsentWithdrawal(): bool
    {
        return true;
    }
}
