<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\Verification\CheckStatus;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Compliance\Verification\RuntimeVerifier;

use function extension_loaded;

#[CoversClass(RuntimeVerifier::class)]
final class RuntimeVerifierTest extends TestCase
{
    public function testVerifyReturns6Results(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(),
        );

        $results = $verifier->verify();

        self::assertCount(6, $results);
    }

    public function testSessionEncryptionActivePass(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(),
            sessionEncryptionActive: true,
        );

        $results = $verifier->verify();
        $sessionResult = $this->findResult($results, 'runtime.session_encryption');

        self::assertSame(CheckStatus::Pass, $sessionResult->status);
    }

    public function testSessionEncryptionDisabledFailsWhenRequired(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(encryptionAtRest: true),
            sessionEncryptionActive: false,
        );

        $results = $verifier->verify();
        $sessionResult = $this->findResult($results, 'runtime.session_encryption');

        self::assertSame(CheckStatus::Fail, $sessionResult->status);
        self::assertNotEmpty($sessionResult->remediations);
    }

    public function testSessionEncryptionDisabledPassesWhenNotRequired(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(encryptionAtRest: false),
            sessionEncryptionActive: false,
        );

        $results = $verifier->verify();
        $sessionResult = $this->findResult($results, 'runtime.session_encryption');

        self::assertSame(CheckStatus::Pass, $sessionResult->status);
    }

    public function testMasterKeyDerivedPass(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(),
            masterKeyDerived: true,
        );

        $results = $verifier->verify();
        $mkResult = $this->findResult($results, 'runtime.master_key_derived');

        self::assertSame(CheckStatus::Pass, $mkResult->status);
    }

    public function testMasterKeyNotDerivedFails(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(),
            masterKeyDerived: false,
        );

        $results = $verifier->verify();
        $mkResult = $this->findResult($results, 'runtime.master_key_derived');

        self::assertSame(CheckStatus::Fail, $mkResult->status);
    }

    public function testAuditLoggingActivePass(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(),
            auditLogActive: true,
        );

        $results = $verifier->verify();
        $auditResult = $this->findResult($results, 'runtime.audit_logging');

        self::assertSame(CheckStatus::Pass, $auditResult->status);
        self::assertSame(ComplianceCheckDomain::AuditLogging, $auditResult->domain);
    }

    public function testAuditLoggingInactiveFailsWithTamperEvidentRequired(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(tamperEvidentAudit: true),
            auditLogActive: false,
        );

        $results = $verifier->verify();
        $auditResult = $this->findResult($results, 'runtime.audit_logging');

        self::assertSame(CheckStatus::Fail, $auditResult->status);
        self::assertStringContainsString('Tamper-evident', $auditResult->message);
    }

    public function testAuditLoggingInactiveFailsWithoutTamperEvident(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(tamperEvidentAudit: false),
            auditLogActive: false,
        );

        $results = $verifier->verify();
        $auditResult = $this->findResult($results, 'runtime.audit_logging');

        self::assertSame(CheckStatus::Fail, $auditResult->status);
        self::assertStringNotContainsString('Tamper-evident', $auditResult->message);
    }

    public function testDatabaseTlsActivePass(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(encryptionInTransit: true),
            dbTlsActive: true,
        );

        $results = $verifier->verify();
        $dbResult = $this->findResult($results, 'runtime.db_tls');

        self::assertSame(CheckStatus::Pass, $dbResult->status);
    }

    public function testDatabaseTlsInactiveFails(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(encryptionInTransit: true),
            dbTlsActive: false,
        );

        $results = $verifier->verify();
        $dbResult = $this->findResult($results, 'runtime.db_tls');

        self::assertSame(CheckStatus::Fail, $dbResult->status);
    }

    public function testDatabaseTlsSkippedWhenNotRequired(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(encryptionInTransit: false),
            dbTlsActive: false,
        );

        $results = $verifier->verify();
        $dbResult = $this->findResult($results, 'runtime.db_tls');

        self::assertSame(CheckStatus::Skip, $dbResult->status);
    }

    public function testFipsCheckSkippedWhenNoEncryptionRequired(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(encryptionAtRest: false, encryptionInTransit: false),
        );

        $results = $verifier->verify();
        $fipsResult = $this->findResult($results, 'runtime.fips_mode');

        self::assertSame(CheckStatus::Skip, $fipsResult->status);
    }

    public function testSodiumExtensionCheck(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(),
        );

        $results = $verifier->verify();
        $sodiumResult = $this->findResult($results, 'runtime.sodium_extension');

        // sodium should be loaded in test environment
        if (extension_loaded('sodium')) {
            self::assertSame(CheckStatus::Pass, $sodiumResult->status);
        } else {
            self::assertSame(CheckStatus::Fail, $sodiumResult->status);
        }
    }

    /**
     * @param list<\Pulsar\Compliance\Verification\CheckResult> $results
     */
    private function findResult(array $results, string $checkId): \Pulsar\Compliance\Verification\CheckResult
    {
        foreach ($results as $result) {
            if ($result->checkId === $checkId) {
                return $result;
            }
        }

        self::fail("No result found for check ID: {$checkId}");
    }

    private function createProfile(
        bool $encryptionAtRest = true,
        bool $encryptionInTransit = true,
        bool $tamperEvidentAudit = true,
    ): ComplianceProfile {
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
            tamperEvidentAudit: $tamperEvidentAudit,
            explicitConsent: true,
            consentWithdrawal: true,
            individualNotification: true,
            breachRegister: true,
        );
    }
}
