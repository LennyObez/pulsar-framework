<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\DataPathVerifier;

#[CoversClass(DataPathVerifier::class)]
final class DataPathVerifierTest extends TestCase
{
    public function testPassesWhenAllMiddlewarePresent(): void
    {
        $verifier = new DataPathVerifier($this->createProfile(true, true));

        $results = $verifier->verify([
            [
                'path' => '/api/payments',
                'classification' => 'pci',
                'middleware' => ['EncryptionMiddleware', 'AuthenticationMiddleware', 'AuditMiddleware', 'CsrfMiddleware'],
            ],
        ]);

        self::assertCount(1, $results);
        self::assertSame(CheckStatus::Pass, $results[0]->status);
    }

    public function testFailsWhenMiddlewareMissing(): void
    {
        $verifier = new DataPathVerifier($this->createProfile(true, true));

        $results = $verifier->verify([
            [
                'path' => '/api/patients',
                'classification' => 'phi',
                'middleware' => ['AuthenticationMiddleware'],
            ],
        ]);

        self::assertCount(1, $results);
        self::assertSame(CheckStatus::Fail, $results[0]->status);
        self::assertStringContainsString('missing middleware', $results[0]->message);
    }

    public function testSkipsUnknownClassification(): void
    {
        $verifier = new DataPathVerifier($this->createProfile(true, true));

        $results = $verifier->verify([
            [
                'path' => '/api/public',
                'classification' => 'public',
                'middleware' => [],
            ],
        ]);

        self::assertCount(1, $results);
        self::assertSame(CheckStatus::Skip, $results[0]->status);
    }

    public function testEncryptionNotRequiredWhenProfileDisabled(): void
    {
        $verifier = new DataPathVerifier($this->createProfile(false, false));

        $results = $verifier->verify([
            [
                'path' => '/api/payments',
                'classification' => 'pci',
                'middleware' => ['AuthenticationMiddleware', 'AuditMiddleware', 'CsrfMiddleware'],
            ],
        ]);

        // Encryption middleware not required when profile has no encryption requirements
        self::assertCount(1, $results);
        self::assertSame(CheckStatus::Pass, $results[0]->status);
    }

    public function testMultipleRoutes(): void
    {
        $verifier = new DataPathVerifier($this->createProfile(true, true));

        $results = $verifier->verify([
            [
                'path' => '/api/a',
                'classification' => 'pii',
                'middleware' => ['AuthenticationMiddleware', 'AuditMiddleware', 'CsrfMiddleware'],
            ],
            [
                'path' => '/api/b',
                'classification' => 'sensitive',
                'middleware' => ['AuthenticationMiddleware', 'AuditMiddleware'],
            ],
        ]);

        self::assertCount(2, $results);
        self::assertSame(CheckStatus::Pass, $results[0]->status);
        self::assertSame(CheckStatus::Pass, $results[1]->status);
    }

    public function testMiddlewareMatchIsCaseInsensitive(): void
    {
        $verifier = new DataPathVerifier($this->createProfile(true, true));

        $results = $verifier->verify([
            [
                'path' => '/api/data',
                'classification' => 'sensitive',
                'middleware' => ['AUTHENTICATION_GUARD', 'audit_logger'],
            ],
        ]);

        self::assertCount(1, $results);
        self::assertSame(CheckStatus::Pass, $results[0]->status);
    }

    private function createProfile(bool $encryptionAtRest, bool $encryptionInTransit): ComplianceProfile
    {
        return new ComplianceProfile(
            enabledFrameworks: [ComplianceFramework::Gdpr],
            passwordMinLength: 12,
            sessionIdleTimeout: 900,
            breachNotificationHours: 72,
            auditRetentionDays: 365,
            dataRetentionDays: 2190,
            mfaRequirement: 'always',
            encryptionAtRest: $encryptionAtRest,
            encryptionInTransit: $encryptionInTransit,
            tamperEvidentAudit: true,
            explicitConsent: true,
            consentWithdrawal: true,
            individualNotification: true,
            breachRegister: true,
        );
    }
}
