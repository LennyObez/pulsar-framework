<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class EidasRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasBreachNotification
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
        return 'privileged';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 3650;
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
        return 3650;
    }

    #[Override]
    public function breachNotificationHours(): int
    {
        return 24;
    }

    #[Override]
    public function requiresIndividualNotification(): bool
    {
        return false;
    }

    #[Override]
    public function requiresBreachRegister(): bool
    {
        return true;
    }
}
