<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class GdprRequirements implements HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasBreachNotification, HasConsentManagement
{
    #[Override]
    public function auditRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return false;
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true;
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true;
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function breachNotificationHours(): int
    {
        return 72;
    }

    #[Override]
    public function requiresIndividualNotification(): bool
    {
        return true;
    }

    #[Override]
    public function requiresBreachRegister(): bool
    {
        return true;
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
