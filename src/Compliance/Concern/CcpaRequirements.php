<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class CcpaRequirements implements HasAuditRequirements, HasDataRetention, HasConsentManagement, HasEncryptionRequirements, HasIncidentReporting
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 730; // 2 years: right-to-know lookback period
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return false;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 730;
    }

    #[Override]
    public function requiresExplicitConsent(): bool
    {
        return true; // CPRA requires explicit consent for sensitive personal information
    }

    #[Override]
    public function requiresConsentWithdrawal(): bool
    {
        return true; // Right to opt-out (§1798.120) and right to delete (§1798.105)
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true; // Safe harbor for encrypted data under §1798.150
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true;
    }

    #[Override]
    public function breachNotificationHours(): ?int
    {
        return null; // "Most expedient time possible": no fixed hour deadline
    }
}
