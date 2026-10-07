<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\PostureScore;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\PostureScore\SecurityControl;

#[CoversNothing]
final class SecurityControlTest extends TestCase
{
    #[Test]
    public function hasFourteenCases(): void
    {
        self::assertCount(14, SecurityControl::cases());
    }

    #[Test]
    #[DataProvider('controlWeightProvider')]
    public function weightReturnsCorrectValue(SecurityControl $control, int $expectedWeight): void
    {
        self::assertSame($expectedWeight, $control->weight());
    }

    /**
     * @return iterable<string, array{SecurityControl, int}>
     */
    public static function controlWeightProvider(): iterable
    {
        yield 'EncryptionAtRest' => [SecurityControl::EncryptionAtRest, 12];
        yield 'EncryptionInTransit' => [SecurityControl::EncryptionInTransit, 12];
        yield 'MfaConfigured' => [SecurityControl::MfaConfigured, 10];
        yield 'CsrfEnabled' => [SecurityControl::CsrfEnabled, 8];
        yield 'RateLimitingEnabled' => [SecurityControl::RateLimitingEnabled, 8];
        yield 'HstsEnabled' => [SecurityControl::HstsEnabled, 8];
        yield 'SessionHardened' => [SecurityControl::SessionHardened, 8];
        yield 'AuditLoggingActive' => [SecurityControl::AuditLoggingActive, 8];
        yield 'CspEnabled' => [SecurityControl::CspEnabled, 6];
        yield 'CorsConfigured' => [SecurityControl::CorsConfigured, 5];
        yield 'ThreatDetectionEnabled' => [SecurityControl::ThreatDetectionEnabled, 5];
        yield 'ComplianceFrameworkEnabled' => [SecurityControl::ComplianceFrameworkEnabled, 4];
        yield 'PasswordPolicyStrong' => [SecurityControl::PasswordPolicyStrong, 4];
        yield 'ApiSigningEnabled' => [SecurityControl::ApiSigningEnabled, 2];
    }

    #[Test]
    public function totalWeightSumsToHundred(): void
    {
        $totalWeight = 0;

        foreach (SecurityControl::cases() as $control) {
            $totalWeight += $control->weight();
        }

        self::assertSame(100, $totalWeight);
    }

    #[Test]
    public function allWeightsArePositive(): void
    {
        foreach (SecurityControl::cases() as $control) {
            self::assertGreaterThan(0, $control->weight(), "Control {$control->value} must have positive weight");
        }
    }

    #[Test]
    public function encryptionControlsHaveHighestWeight(): void
    {
        $maxWeight = 0;

        foreach (SecurityControl::cases() as $control) {
            if ($control->weight() > $maxWeight) {
                $maxWeight = $control->weight();
            }
        }

        self::assertSame(12, SecurityControl::EncryptionAtRest->weight());
        self::assertSame(12, SecurityControl::EncryptionInTransit->weight());
        self::assertSame($maxWeight, SecurityControl::EncryptionAtRest->weight());
    }

    #[Test]
    #[DataProvider('backedValueProvider')]
    public function backedValues(SecurityControl $control, string $expected): void
    {
        self::assertSame($expected, $control->value);
    }

    /**
     * @return iterable<string, array{SecurityControl, string}>
     */
    public static function backedValueProvider(): iterable
    {
        yield 'EncryptionAtRest' => [SecurityControl::EncryptionAtRest, 'encryption_at_rest'];
        yield 'EncryptionInTransit' => [SecurityControl::EncryptionInTransit, 'encryption_in_transit'];
        yield 'MfaConfigured' => [SecurityControl::MfaConfigured, 'mfa_configured'];
        yield 'CsrfEnabled' => [SecurityControl::CsrfEnabled, 'csrf_enabled'];
        yield 'RateLimitingEnabled' => [SecurityControl::RateLimitingEnabled, 'rate_limiting_enabled'];
        yield 'HstsEnabled' => [SecurityControl::HstsEnabled, 'hsts_enabled'];
        yield 'SessionHardened' => [SecurityControl::SessionHardened, 'session_hardened'];
        yield 'AuditLoggingActive' => [SecurityControl::AuditLoggingActive, 'audit_logging_active'];
        yield 'CspEnabled' => [SecurityControl::CspEnabled, 'csp_enabled'];
        yield 'CorsConfigured' => [SecurityControl::CorsConfigured, 'cors_configured'];
        yield 'ThreatDetectionEnabled' => [SecurityControl::ThreatDetectionEnabled, 'threat_detection_enabled'];
        yield 'ComplianceFrameworkEnabled' => [SecurityControl::ComplianceFrameworkEnabled, 'compliance_framework_enabled'];
        yield 'PasswordPolicyStrong' => [SecurityControl::PasswordPolicyStrong, 'password_policy_strong'];
        yield 'ApiSigningEnabled' => [SecurityControl::ApiSigningEnabled, 'api_signing_enabled'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (SecurityControl::cases() as $control) {
            self::assertSame($control, SecurityControl::from($control->value));
        }
    }

    #[Test]
    public function weightHierarchyIsConsistent(): void
    {
        // Encryption > MFA > Session/CSRF/Rate/HSTS/Audit > CSP > CORS/Threat > Compliance/Password > API
        self::assertGreaterThan(SecurityControl::MfaConfigured->weight(), SecurityControl::EncryptionAtRest->weight());
        self::assertGreaterThan(SecurityControl::CsrfEnabled->weight(), SecurityControl::MfaConfigured->weight());
        self::assertGreaterThan(SecurityControl::CspEnabled->weight(), SecurityControl::CsrfEnabled->weight());
        self::assertGreaterThan(SecurityControl::CorsConfigured->weight(), SecurityControl::CspEnabled->weight());
        self::assertGreaterThan(SecurityControl::ApiSigningEnabled->weight(), SecurityControl::ComplianceFrameworkEnabled->weight());
    }
}
