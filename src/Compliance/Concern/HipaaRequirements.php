<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class HipaaRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasBreachNotification
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8;
    }

    #[Override]
    public function sessionIdleTimeout(): ?int
    {
        return null;
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'sensitive-data';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 2190;
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
        return 2190;
    }

    #[Override]
    public function breachNotificationHours(): int
    {
        return 1440;
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
}
