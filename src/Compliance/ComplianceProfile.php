<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Resolved compliance profile representing the most restrictive settings
 * across all enabled compliance frameworks.
 *
 * This DTO is the output of ComplianceProfileResolver and can be injected
 * into SessionConfig, PasswordHasher, RetentionSchedule, IncidentReporter,
 * and other security-sensitive components to enforce regulatory requirements.
 */
#[Api(since: '1.0.0')]
final readonly class ComplianceProfile
{
    /**
     * @param list<ComplianceFramework> $enabledFrameworks Frameworks that contributed to this profile
     * @param int    $passwordMinLength        Most restrictive minimum password length
     * @param int    $sessionIdleTimeout       Most restrictive idle timeout in seconds
     * @param int    $breachNotificationHours  Most restrictive breach notification deadline in hours
     * @param int    $auditRetentionDays       Most restrictive audit retention in days
     * @param int    $dataRetentionDays        Most restrictive data retention in days
     * @param string $mfaRequirement           Broadest MFA scope: 'always'|'privileged'|'sensitive-data'|'none'
     * @param bool   $encryptionAtRest         Whether encryption at rest is required
     * @param bool   $encryptionInTransit      Whether encryption in transit is required
     * @param bool   $tamperEvidentAudit       Whether HMAC-chained audit is required
     * @param bool   $explicitConsent          Whether explicit consent is required
     * @param bool   $consentWithdrawal        Whether consent withdrawal must be supported
     * @param bool   $individualNotification   Whether affected individuals must be notified on breach
     * @param bool   $breachRegister           Whether a breach register must be maintained
     */
    public function __construct(
        public array $enabledFrameworks,
        public int $passwordMinLength,
        public int $sessionIdleTimeout,
        public int $breachNotificationHours,
        public int $auditRetentionDays,
        public int $dataRetentionDays,
        public string $mfaRequirement,
        public bool $encryptionAtRest,
        public bool $encryptionInTransit,
        public bool $tamperEvidentAudit,
        public bool $explicitConsent,
        public bool $consentWithdrawal,
        public bool $individualNotification,
        public bool $breachRegister,
    ) {}

    /**
     * Whether MFA is required in any scope (not 'none').
     */
    #[NoDiscard]
    public function requiresMfa(): bool
    {
        return $this->mfaRequirement !== 'none';
    }

    /**
     * Whether MFA is required for all operations (broadest scope).
     */
    #[NoDiscard]
    public function requiresUniversalMfa(): bool
    {
        return $this->mfaRequirement === 'always';
    }

    /**
     * Whether any encryption requirement is active.
     */
    #[NoDiscard]
    public function requiresEncryption(): bool
    {
        return $this->encryptionAtRest || $this->encryptionInTransit;
    }

    /**
     * Check if a specific framework is enabled in this profile.
     */
    #[NoDiscard]
    public function hasFramework(ComplianceFramework $framework): bool
    {
        foreach ($this->enabledFrameworks as $enabled) {
            if ($enabled === $framework) {
                return true;
            }
        }

        return false;
    }
}
