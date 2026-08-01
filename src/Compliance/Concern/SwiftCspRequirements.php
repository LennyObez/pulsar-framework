<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Concern;

use Override;

/**
 * @internal
 */
final readonly class SwiftCspRequirements implements HasAccessControl, HasAuditRequirements, HasEncryptionRequirements, HasDataRetention, HasIncidentReporting, HasBreachNotification
{
    #[Override]
    public function passwordMinLength(): int
    {
        return 12; // Control 4.1: strong password policy
    }

    #[Override]
    public function sessionIdleTimeout(): int
    {
        return 900; // 15 min: SWIFT operator session policy
    }

    #[Override]
    public function mfaRequirement(): string
    {
        return 'always'; // Control 4.2: mandatory for all SWIFT access
    }

    #[Override]
    public function auditRetentionDays(): int
    {
        return 2555; // 7 years: financial record retention
    }

    #[Override]
    public function requiresTamperEvidentAudit(): bool
    {
        return true; // Controls 6.3, 6.4: database and logging integrity
    }

    #[Override]
    public function requiresEncryptionAtRest(): bool
    {
        return true; // Control 2.5A: data protection at rest
    }

    #[Override]
    public function requiresEncryptionInTransit(): bool
    {
        return true; // Control 2.1: internal data flow security
    }

    #[Override]
    public function dataRetentionDays(): int
    {
        return 2555; // 7 years
    }

    #[Override]
    public function breachNotificationHours(): ?int
    {
        return null; // No fixed hour deadline; notify SWIFT via ISAC
    }

    #[Override]
    public function requiresIndividualNotification(): bool
    {
        return false; // Reports to SWIFT, not to individuals
    }

    #[Override]
    public function requiresBreachRegister(): bool
    {
        return true; // Control 7.1: incident record keeping
    }
}
