<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\Verification\ComplianceRegressionException;
use Pulsar\Compliance\Verification\RegressionDetector;

#[CoversClass(RegressionDetector::class)]
final class RegressionDetectorTest extends TestCase
{
    public function testNoViolationsWhenNoFrameworksEnabled(): void
    {
        $profile = $this->createProfile([]);
        $detector = new RegressionDetector($profile);

        $violations = $detector->detect(
            sessionIdleTimeout: 99999,
            passwordMinLength: 1,
            hstsMaxAge: 0,
            encryptionAtRest: false,
            mfaScope: 'none',
            tamperEvidentAudit: false,
        );

        self::assertSame([], $violations);
    }

    public function testNoViolationsWhenCompliant(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $detector = new RegressionDetector($profile);

        $violations = $detector->detect(
            sessionIdleTimeout: 600,
            passwordMinLength: 14,
            hstsMaxAge: 63072000,
            encryptionAtRest: true,
            mfaScope: 'always',
            tamperEvidentAudit: true,
        );

        self::assertSame([], $violations);
    }

    public function testDetectsSessionTimeoutRegression(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $detector = new RegressionDetector($profile);

        $violations = $detector->detect(
            sessionIdleTimeout: 7200,
            passwordMinLength: 14,
            hstsMaxAge: 63072000,
            encryptionAtRest: true,
            mfaScope: 'always',
            tamperEvidentAudit: true,
        );

        self::assertCount(1, $violations);
        self::assertSame('session.idle_timeout', $violations[0]->constraint);
    }

    public function testDetectsPasswordLengthRegression(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $detector = new RegressionDetector($profile);

        $violations = $detector->detect(
            sessionIdleTimeout: 600,
            passwordMinLength: 6,
            hstsMaxAge: 63072000,
            encryptionAtRest: true,
            mfaScope: 'always',
            tamperEvidentAudit: true,
        );

        self::assertCount(1, $violations);
        self::assertSame('auth.password_min_length', $violations[0]->constraint);
    }

    public function testDetectsHstsRegression(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $detector = new RegressionDetector($profile);

        $violations = $detector->detect(
            sessionIdleTimeout: 600,
            passwordMinLength: 14,
            hstsMaxAge: 86400,
            encryptionAtRest: true,
            mfaScope: 'always',
            tamperEvidentAudit: true,
        );

        self::assertCount(1, $violations);
        self::assertSame('security.hsts_max_age', $violations[0]->constraint);
    }

    public function testDetectsEncryptionAtRestRegression(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $detector = new RegressionDetector($profile);

        $violations = $detector->detect(
            sessionIdleTimeout: 600,
            passwordMinLength: 14,
            hstsMaxAge: 63072000,
            encryptionAtRest: false,
            mfaScope: 'always',
            tamperEvidentAudit: true,
        );

        self::assertCount(1, $violations);
        self::assertSame('encryption.at_rest', $violations[0]->constraint);
    }

    public function testDetectsMfaScopeRegression(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $detector = new RegressionDetector($profile);

        $violations = $detector->detect(
            sessionIdleTimeout: 600,
            passwordMinLength: 14,
            hstsMaxAge: 63072000,
            encryptionAtRest: true,
            mfaScope: 'none',
            tamperEvidentAudit: true,
        );

        self::assertCount(1, $violations);
        self::assertSame('auth.mfa_scope', $violations[0]->constraint);
    }

    public function testDetectsTamperEvidentRegression(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $detector = new RegressionDetector($profile);

        $violations = $detector->detect(
            sessionIdleTimeout: 600,
            passwordMinLength: 14,
            hstsMaxAge: 63072000,
            encryptionAtRest: true,
            mfaScope: 'always',
            tamperEvidentAudit: false,
        );

        self::assertCount(1, $violations);
        self::assertSame('audit.tamper_evident', $violations[0]->constraint);
    }

    public function testEnforceThrowsOnViolations(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $detector = new RegressionDetector($profile);

        $this->expectException(ComplianceRegressionException::class);

        $detector->enforce(
            sessionIdleTimeout: 99999,
            passwordMinLength: 1,
            hstsMaxAge: 0,
            encryptionAtRest: false,
            mfaScope: 'none',
            tamperEvidentAudit: false,
        );
    }

    public function testEnforceDoesNotThrowWhenCompliant(): void
    {
        $profile = $this->createProfile([ComplianceFramework::Gdpr]);
        $detector = new RegressionDetector($profile);

        $detector->enforce(
            sessionIdleTimeout: 600,
            passwordMinLength: 14,
            hstsMaxAge: 63072000,
            encryptionAtRest: true,
            mfaScope: 'always',
            tamperEvidentAudit: true,
        );

        // No exception thrown — verify the method completed
        self::addToAssertionCount(1);
    }

    /**
     * @param list<ComplianceFramework> $frameworks
     */
    private function createProfile(array $frameworks): ComplianceProfile
    {
        return new ComplianceProfile(
            enabledFrameworks: $frameworks,
            passwordMinLength: 12,
            sessionIdleTimeout: 900,
            breachNotificationHours: 72,
            auditRetentionDays: 365,
            dataRetentionDays: 2190,
            mfaRequirement: 'always',
            encryptionAtRest: true,
            encryptionInTransit: true,
            tamperEvidentAudit: true,
            explicitConsent: true,
            consentWithdrawal: true,
            individualNotification: true,
            breachRegister: true,
        );
    }
}
