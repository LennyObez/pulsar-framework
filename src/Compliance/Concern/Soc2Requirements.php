<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class Soc2Requirements implements HasAccessControl, HasAuditRequirements, HasDataRetention, HasIncidentReporting
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
        return 365;
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return false;
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
