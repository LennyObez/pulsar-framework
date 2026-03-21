<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Concern\FrameworkRequirements;
use Pulsar\Compliance\Concern\HasAccessControl;
use Pulsar\Compliance\Concern\HasAuditRequirements;
use Pulsar\Compliance\Concern\HasBreachNotification;
use Pulsar\Compliance\Concern\HasConsentManagement;
use Pulsar\Compliance\Concern\HasDataRetention;
use Pulsar\Compliance\Concern\HasEncryptionRequirements;
use Pulsar\Compliance\Concern\HasIncidentReporting;

use function max;
use function min;

/**
 * Resolves a unified ComplianceProfile from a list of enabled frameworks.
 *
 * For each configurable parameter, the resolver picks the MOST RESTRICTIVE
 * value across all enabled frameworks:
 *
 * - Numeric minimums (password length, retention): use the LARGEST value
 * - Numeric maximums (idle timeout, breach deadline): use the SMALLEST value
 * - Boolean flags (encryption, consent): OR across all frameworks (true wins)
 * - MFA scope: use the BROADEST scope ('always' > 'privileged' > 'sensitive-data' > 'none')
 *
 * When no framework specifies a value, sensible defaults are used.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ComplianceProfileResolver
{
    /** Default password minimum when no framework specifies one. */
    private const int DEFAULT_PASSWORD_MIN_LENGTH = 8;

    /** Default session idle timeout (15 minutes) when no framework specifies one. */
    private const int DEFAULT_SESSION_IDLE_TIMEOUT = 900;

    /** Default breach notification deadline (72 hours) when no framework specifies one. */
    private const int DEFAULT_BREACH_NOTIFICATION_HOURS = 72;

    /** Default audit retention (1 year) when no framework specifies one. */
    private const int DEFAULT_AUDIT_RETENTION_DAYS = 365;

    /** Default data retention (1 year) when no framework specifies one. */
    private const int DEFAULT_DATA_RETENTION_DAYS = 365;

    /** MFA scope ranking from broadest (0) to narrowest (3). */
    private const array MFA_SCOPE_RANK = [
        'always' => 0,
        'privileged' => 1,
        'sensitive-data' => 2,
        'none' => 3,
    ];

    /**
     * Resolve the most restrictive ComplianceProfile for the given frameworks.
     *
     * @param list<ComplianceFramework> $frameworks
     */
    #[NoDiscard]
    public function resolve(array $frameworks): ComplianceProfile
    {
        if ($frameworks === []) {
            return $this->defaultProfile();
        }

        $requirements = $this->collectRequirements($frameworks);

        return new ComplianceProfile(
            enabledFrameworks: $frameworks,
            passwordMinLength: $this->resolvePasswordMinLength($requirements),
            sessionIdleTimeout: $this->resolveSessionIdleTimeout($requirements),
            breachNotificationHours: $this->resolveBreachNotificationHours($requirements),
            auditRetentionDays: $this->resolveAuditRetentionDays($requirements),
            dataRetentionDays: $this->resolveDataRetentionDays($requirements),
            mfaRequirement: $this->resolveMfaRequirement($requirements),
            encryptionAtRest: $this->resolveEncryptionAtRest($requirements),
            encryptionInTransit: $this->resolveEncryptionInTransit($requirements),
            tamperEvidentAudit: $this->resolveTamperEvidentAudit($requirements),
            explicitConsent: $this->resolveExplicitConsent($requirements),
            consentWithdrawal: $this->resolveConsentWithdrawal($requirements),
            individualNotification: $this->resolveIndividualNotification($requirements),
            breachRegister: $this->resolveBreachRegister($requirements),
        );
    }

    /**
     * Build a default profile with baseline security settings.
     */
    private function defaultProfile(): ComplianceProfile
    {
        return new ComplianceProfile(
            enabledFrameworks: [],
            passwordMinLength: self::DEFAULT_PASSWORD_MIN_LENGTH,
            sessionIdleTimeout: self::DEFAULT_SESSION_IDLE_TIMEOUT,
            breachNotificationHours: self::DEFAULT_BREACH_NOTIFICATION_HOURS,
            auditRetentionDays: self::DEFAULT_AUDIT_RETENTION_DAYS,
            dataRetentionDays: self::DEFAULT_DATA_RETENTION_DAYS,
            mfaRequirement: 'none',
            encryptionAtRest: false,
            encryptionInTransit: true,
            tamperEvidentAudit: false,
            explicitConsent: false,
            consentWithdrawal: false,
            individualNotification: false,
            breachRegister: false,
        );
    }

    /**
     * Map each framework enum to its requirements object.
     *
     * @param list<ComplianceFramework> $frameworks
     * @return list<object>
     */
    private function collectRequirements(array $frameworks): array
    {
        $requirements = [];

        foreach ($frameworks as $framework) {
            $req = match ($framework) {
                ComplianceFramework::PciDss => FrameworkRequirements::pciDss(),
                ComplianceFramework::Hipaa => FrameworkRequirements::hipaa(),
                ComplianceFramework::Gdpr => FrameworkRequirements::gdpr(),
                ComplianceFramework::Soc2 => FrameworkRequirements::soc2(),
                ComplianceFramework::Nis2 => FrameworkRequirements::nis2(),
                ComplianceFramework::Iso27001 => FrameworkRequirements::iso27001(),
                ComplianceFramework::Psd2 => FrameworkRequirements::psd2(),
                ComplianceFramework::Eidas => FrameworkRequirements::eidas(),
                ComplianceFramework::Iso42001 => FrameworkRequirements::iso42001(),
                ComplianceFramework::Hl7Fhir => FrameworkRequirements::hl7Fhir(),
                ComplianceFramework::Mdr => FrameworkRequirements::mdr(),
                ComplianceFramework::Iso13485 => FrameworkRequirements::iso13485(),
                ComplianceFramework::Dora => FrameworkRequirements::dora(),
                ComplianceFramework::SwiftCsp => FrameworkRequirements::swiftCsp(),
                ComplianceFramework::Ccpa => FrameworkRequirements::ccpa(),
                ComplianceFramework::NistCsf => FrameworkRequirements::nistCsf(),
                ComplianceFramework::Dsa => FrameworkRequirements::dsa(),
                ComplianceFramework::DataAct => FrameworkRequirements::dataAct(),
            };

            $requirements[] = $req;
        }

        return $requirements;
    }

    /**
     * Most restrictive = LARGEST minimum password length.
     *
     * @param list<object> $requirements
     */
    private function resolvePasswordMinLength(array $requirements): int
    {
        $values = [self::DEFAULT_PASSWORD_MIN_LENGTH];

        foreach ($requirements as $req) {
            if ($req instanceof HasAccessControl) {
                $values[] = $req->passwordMinLength();
            }
        }

        return max($values);
    }

    /**
     * Most restrictive = SMALLEST idle timeout (strictest lockout).
     *
     * Only considers frameworks that specify a timeout. If none do,
     * falls back to the default.
     *
     * @param list<object> $requirements
     */
    private function resolveSessionIdleTimeout(array $requirements): int
    {
        $values = [];

        foreach ($requirements as $req) {
            if ($req instanceof HasAccessControl) {
                $timeout = $req->sessionIdleTimeout();

                if ($timeout !== null) {
                    $values[] = $timeout;
                }
            }
        }

        if ($values === []) {
            return self::DEFAULT_SESSION_IDLE_TIMEOUT;
        }

        return min($values);
    }

    /**
     * Most restrictive = SMALLEST breach notification deadline (tightest window).
     *
     * @param list<object> $requirements
     */
    private function resolveBreachNotificationHours(array $requirements): int
    {
        $values = [];

        foreach ($requirements as $req) {
            if ($req instanceof HasIncidentReporting) {
                $hours = $req->breachNotificationHours();

                if ($hours !== null) {
                    $values[] = $hours;
                }
            }
        }

        if ($values === []) {
            return self::DEFAULT_BREACH_NOTIFICATION_HOURS;
        }

        return min($values);
    }

    /**
     * Most restrictive = LARGEST audit retention (longest keeping period).
     *
     * @param list<object> $requirements
     */
    private function resolveAuditRetentionDays(array $requirements): int
    {
        $values = [self::DEFAULT_AUDIT_RETENTION_DAYS];

        foreach ($requirements as $req) {
            if ($req instanceof HasAuditRequirements) {
                $values[] = $req->auditRetentionDays();
            }
        }

        return max($values);
    }

    /**
     * Most restrictive = LARGEST data retention (longest keeping period).
     *
     * @param list<object> $requirements
     */
    private function resolveDataRetentionDays(array $requirements): int
    {
        $values = [self::DEFAULT_DATA_RETENTION_DAYS];

        foreach ($requirements as $req) {
            if ($req instanceof HasDataRetention) {
                $values[] = $req->dataRetentionDays();
            }
        }

        return max($values);
    }

    /**
     * Most restrictive = BROADEST MFA scope.
     *
     * Ranking: 'always' > 'privileged' > 'sensitive-data' > 'none'.
     * The broadest scope wins because it supersedes all narrower scopes.
     *
     * @param list<object> $requirements
     */
    private function resolveMfaRequirement(array $requirements): string
    {
        $bestRank = self::MFA_SCOPE_RANK['none'];
        $bestScope = 'none';

        foreach ($requirements as $req) {
            if ($req instanceof HasAccessControl) {
                $scope = $req->mfaRequirement();
                $rank = self::MFA_SCOPE_RANK[$scope] ?? self::MFA_SCOPE_RANK['none'];

                if ($rank < $bestRank) {
                    $bestRank = $rank;
                    $bestScope = $scope;
                }
            }
        }

        return $bestScope;
    }

    /**
     * Any framework requiring encryption at rest → true.
     *
     * @param list<object> $requirements
     */
    private function resolveEncryptionAtRest(array $requirements): bool
    {
        foreach ($requirements as $req) {
            if ($req instanceof HasEncryptionRequirements && $req->requiresEncryptionAtRest()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any framework requiring encryption in transit → true.
     *
     * @param list<object> $requirements
     */
    private function resolveEncryptionInTransit(array $requirements): bool
    {
        foreach ($requirements as $req) {
            if ($req instanceof HasEncryptionRequirements && $req->requiresEncryptionInTransit()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any framework requiring tamper-evident audit → true.
     *
     * @param list<object> $requirements
     */
    private function resolveTamperEvidentAudit(array $requirements): bool
    {
        foreach ($requirements as $req) {
            if ($req instanceof HasAuditRequirements && $req->requiresTamperEvidentAudit()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any framework requiring explicit consent → true.
     *
     * @param list<object> $requirements
     */
    private function resolveExplicitConsent(array $requirements): bool
    {
        foreach ($requirements as $req) {
            if ($req instanceof HasConsentManagement && $req->requiresExplicitConsent()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any framework requiring consent withdrawal → true.
     *
     * @param list<object> $requirements
     */
    private function resolveConsentWithdrawal(array $requirements): bool
    {
        foreach ($requirements as $req) {
            if ($req instanceof HasConsentManagement && $req->requiresConsentWithdrawal()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any framework requiring individual breach notification → true.
     *
     * @param list<object> $requirements
     */
    private function resolveIndividualNotification(array $requirements): bool
    {
        foreach ($requirements as $req) {
            if ($req instanceof HasBreachNotification && $req->requiresIndividualNotification()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any framework requiring a breach register → true.
     *
     * @param list<object> $requirements
     */
    private function resolveBreachRegister(array $requirements): bool
    {
        foreach ($requirements as $req) {
            if ($req instanceof HasBreachNotification && $req->requiresBreachRegister()) {
                return true;
            }
        }

        return false;
    }
}
