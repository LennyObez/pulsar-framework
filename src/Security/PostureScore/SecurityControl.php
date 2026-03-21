<?php

declare(strict_types=1);

namespace Pulsar\Security\PostureScore;

use Pulsar\Api\Api;

/**
 * Enumeration of security controls that contribute to the posture score.
 *
 * Each control has a weight (points deducted when missing).
 * @api
 */
#[Api(since: '1.0.0')]
enum SecurityControl: string
{
    case EncryptionAtRest = 'encryption_at_rest';
    case EncryptionInTransit = 'encryption_in_transit';
    case MfaConfigured = 'mfa_configured';
    case CsrfEnabled = 'csrf_enabled';
    case RateLimitingEnabled = 'rate_limiting_enabled';
    case HstsEnabled = 'hsts_enabled';
    case SessionHardened = 'session_hardened';
    case AuditLoggingActive = 'audit_logging_active';
    case CspEnabled = 'csp_enabled';
    case CorsConfigured = 'cors_configured';
    case ThreatDetectionEnabled = 'threat_detection_enabled';
    case ComplianceFrameworkEnabled = 'compliance_framework_enabled';
    case PasswordPolicyStrong = 'password_policy_strong';
    case ApiSigningEnabled = 'api_signing_enabled';

    /**
     * Points deducted from the maximum score when this control is missing.
     */
    public function weight(): int
    {
        return match ($this) {
            self::EncryptionAtRest => 12,
            self::EncryptionInTransit => 12,
            self::MfaConfigured => 10,
            self::CsrfEnabled => 8,
            self::RateLimitingEnabled => 8,
            self::HstsEnabled => 8,
            self::SessionHardened => 8,
            self::AuditLoggingActive => 8,
            self::CspEnabled => 6,
            self::CorsConfigured => 5,
            self::ThreatDetectionEnabled => 5,
            self::ComplianceFrameworkEnabled => 4,
            self::PasswordPolicyStrong => 4,
            self::ApiSigningEnabled => 2,
        };
    }
}
