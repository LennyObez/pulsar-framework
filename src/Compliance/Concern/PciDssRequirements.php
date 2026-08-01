<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class PciDssRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasIncidentReporting
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 12;
    }

    #[Override]
    public function sessionIdleTimeout(): int
    {
        return 900;
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'privileged';
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 365;
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
        return 365;
    }

    #[Override]
    public function breachNotificationHours(): ?int
    {
        return null;
    }
}
