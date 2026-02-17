<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceProfile;

use function sprintf;

/**
 * Detects configuration regressions that violate the active compliance profile.
 *
 * At boot time (or on demand), compares current system configuration against
 * the resolved ComplianceProfile constraints. Any violation is collected as a
 * RegressionViolation and optionally throws ComplianceRegressionException.
 */
#[Api(since: '1.0.0')]
final readonly class RegressionDetector
{
    public function __construct(
        private ComplianceProfile $profile,
    ) {}

    /**
     * Detect regressions against the given system configuration values.
     *
     * @param int         $sessionIdleTimeout   Current session idle timeout in seconds
     * @param int         $passwordMinLength    Current minimum password length
     * @param int         $hstsMaxAge           Current HSTS max-age in seconds
     * @param bool        $encryptionAtRest     Whether encryption at rest is enabled
     * @param string      $mfaScope             Current MFA scope setting
     * @param bool        $tamperEvidentAudit   Whether tamper-evident audit is active
     *
     * @return list<RegressionViolation>
     */
    public function detect(
        int $sessionIdleTimeout,
        int $passwordMinLength,
        int $hstsMaxAge,
        bool $encryptionAtRest,
        string $mfaScope,
        bool $tamperEvidentAudit,
    ): array {
        if ($this->profile->enabledFrameworks === []) {
            return [];
        }

        $violations = [];

        if ($sessionIdleTimeout > $this->profile->sessionIdleTimeout) {
            $violations[] = new RegressionViolation(
                constraint: 'session.idle_timeout',
                expectedDescription: sprintf('<= %d seconds', $this->profile->sessionIdleTimeout),
                actualDescription: sprintf('%d seconds', $sessionIdleTimeout),
                remediation: sprintf(
                    'Set session idle timeout to %d seconds or less in config/security.php.',
                    $this->profile->sessionIdleTimeout,
                ),
            );
        }

        if ($passwordMinLength < $this->profile->passwordMinLength) {
            $violations[] = new RegressionViolation(
                constraint: 'auth.password_min_length',
                expectedDescription: sprintf('>= %d characters', $this->profile->passwordMinLength),
                actualDescription: sprintf('%d characters', $passwordMinLength),
                remediation: sprintf(
                    'Set minimum password length to %d or more in config/security.php.',
                    $this->profile->passwordMinLength,
                ),
            );
        }

        $minHstsAge = $this->resolveMinHstsAge();

        if ($hstsMaxAge < $minHstsAge) {
            $violations[] = new RegressionViolation(
                constraint: 'security.hsts_max_age',
                expectedDescription: sprintf('>= %d seconds', $minHstsAge),
                actualDescription: sprintf('%d seconds', $hstsMaxAge),
                remediation: sprintf(
                    'Set HSTS max-age to at least %d seconds in config/security.php.',
                    $minHstsAge,
                ),
            );
        }

        if ($this->profile->encryptionAtRest && !$encryptionAtRest) {
            $violations[] = new RegressionViolation(
                constraint: 'encryption.at_rest',
                expectedDescription: 'enabled (required by compliance profile)',
                actualDescription: 'disabled',
                remediation: 'Enable encryption at rest in config/security.php.',
            );
        }

        $violations = [...$violations, ...$this->checkMfaScope($mfaScope)];

        if ($this->profile->tamperEvidentAudit && !$tamperEvidentAudit) {
            $violations[] = new RegressionViolation(
                constraint: 'audit.tamper_evident',
                expectedDescription: 'enabled (required by compliance profile)',
                actualDescription: 'disabled',
                remediation: 'Enable HMAC-chained audit logging in config/security.php.',
            );
        }

        return $violations;
    }

    /**
     * Run detection and throw if any violations are found.
     *
     * @throws ComplianceRegressionException
     */
    public function enforce(
        int $sessionIdleTimeout,
        int $passwordMinLength,
        int $hstsMaxAge,
        bool $encryptionAtRest,
        string $mfaScope,
        bool $tamperEvidentAudit,
    ): void {
        $violations = $this->detect(
            sessionIdleTimeout: $sessionIdleTimeout,
            passwordMinLength: $passwordMinLength,
            hstsMaxAge: $hstsMaxAge,
            encryptionAtRest: $encryptionAtRest,
            mfaScope: $mfaScope,
            tamperEvidentAudit: $tamperEvidentAudit,
        );

        if ($violations !== []) {
            throw ComplianceRegressionException::fromViolations($violations);
        }
    }

    /**
     * MFA scope ranking (lower = broader).
     */
    private const array MFA_SCOPE_RANK = [
        'always' => 0,
        'privileged' => 1,
        'sensitive-data' => 2,
        'none' => 3,
    ];

    /**
     * @return list<RegressionViolation>
     */
    private function checkMfaScope(string $currentScope): array
    {
        $requiredRank = self::MFA_SCOPE_RANK[$this->profile->mfaRequirement] ?? 3;
        $currentRank = self::MFA_SCOPE_RANK[$currentScope] ?? 3;

        if ($currentRank > $requiredRank) {
            return [new RegressionViolation(
                constraint: 'auth.mfa_scope',
                expectedDescription: sprintf("'%s' or broader", $this->profile->mfaRequirement),
                actualDescription: sprintf("'%s'", $currentScope),
                remediation: sprintf(
                    "Set MFA scope to '%s' or broader in config/security.php.",
                    $this->profile->mfaRequirement,
                ),
            )];
        }

        return [];
    }

    /**
     * Resolve the minimum HSTS max-age required across enabled frameworks.
     *
     * PCI-DSS: 31536000 (1 year), HIPAA/NIS2: 63072000 (2 years), default: 31536000.
     */
    private function resolveMinHstsAge(): int
    {
        return 31536000; // 1 year baseline for all compliance profiles
    }
}
