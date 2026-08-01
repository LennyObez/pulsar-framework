<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class DoraRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasBreachNotification
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8;
    }

    #[Override]
    public function sessionIdleTimeout(): int
    {
        return 300; // 5 min: aligned with PSD2 for financial entities
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'privileged';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 1825; // 5 years
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true;
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
        return 1825;
    }

    #[Override]
    public function breachNotificationHours(): int
    {
        return 4; // Major ICT incident: initial notification within 4 hours
    }

    #[Override]
    public function requiresIndividualNotification(): bool
    {
        return false; // DORA reports to competent authority, not individuals
    }

    #[Override]
    public function requiresBreachRegister(): bool
    {
        return true;
    }
}
