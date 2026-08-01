<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class NistCsfRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasIncidentReporting
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 8; // NIST SP 800-63B reference
    }

    #[Override]
    public function sessionIdleTimeout(): ?int
    {
        return null; // Organizational risk decision
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'privileged'; // PR.AA: identity management for privileged access
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 365; // DE.CM: continuous monitoring baseline
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true; // DE.AE: adverse event analysis requires integrity
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true; // PR.DS: data security at rest
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true; // PR.DS: data security in transit
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 365;
    }

    #[Override]
    public function breachNotificationHours(): ?int
    {
        return null; // No fixed deadline: risk-based approach
    }
}
