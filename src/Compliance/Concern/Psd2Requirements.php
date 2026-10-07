<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class Psd2Requirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasBreachNotification
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8;
    }

    #[Override]
    public function sessionIdleTimeout(): int
    {
        return 300;
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'always';
    }

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
    public function requiresEncryptionAtRest(): bool
    {
        return false;
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
        return 4;
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
