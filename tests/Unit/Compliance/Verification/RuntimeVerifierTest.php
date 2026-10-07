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
use Pulsar\Security\Crypto\AesGcmCipherSuite;
use Pulsar\Security\Crypto\FipsValidator;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\SodiumCipherSuite;
use Pulsar\Security\Crypto\SubKeyId;
use ReflectionClass;

use function extension_loaded;
use function str_repeat;
use function strlen;

use const SODIUM_CRYPTO_KDF_CONTEXTBYTES;

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

    /**
     * Disabled and not required is a SKIP, not a pass.
     *
     * It used to pass, on the reasoning that the profile asked for nothing — which
     * is a statement about the profile, not about the deployment, and reads in a
     * report as "session encryption is fine". Anything turning these results into
     * compliance evidence would have published the absence of a control as the
     * control holding; the evidence adapter reads Skip as "the run did not happen"
     * and grades it absent, which is what actually occurred.
     */
    public function testSessionEncryptionDisabledIsSkippedRatherThanPassedWhenNotRequired(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(encryptionAtRest: false),
            sessionEncryptionActive: false,
        );

        $results = $verifier->verify();
        $sessionResult = $this->findResult($results, 'runtime.session_encryption');

        self::assertSame(CheckStatus::Skip, $sessionResult->status);
        self::assertStringContainsString('DISABLED', $sessionResult->message);
    }

    public function testMasterKeyDerivedPass(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(),
            masterKey: self::masterKey(),
        );

        $results = $verifier->verify();
        $mkResult = $this->findResult($results, 'runtime.master_key_derived');

        self::assertSame(CheckStatus::Pass, $mkResult->status);
    }

    public function testMasterKeyNotDerivedFails(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(),
            masterKey: null,
        );

        $results = $verifier->verify();
        $mkResult = $this->findResult($results, 'runtime.master_key_derived');

        self::assertSame(CheckStatus::Fail, $mkResult->status);
    }

    /**
     * The check must report the KDF, not a binding.
     *
     * `$container->has(MasterKey::class)` used to be the whole basis for the
     * message "Master key uses proper KDF derivation", so the evidence had to
     * name something the KDF did. It now names the function, the subkey length
     * and the domain-separation result, none of which a container lookup knows.
     */
    public function testMasterKeyDerivationEvidenceNamesWhatWasMeasured(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(),
            masterKey: self::masterKey(),
        );

        $result = $this->findResult($verifier->verify(), 'runtime.master_key_derived');

        self::assertSame(CheckStatus::Pass, $result->status);
        self::assertContains('kdf: sodium_crypto_kdf_derive_from_key', $result->evidence);
        self::assertContains('subkey_bytes: 32', $result->evidence);
        self::assertContains('domain_separation: verified across two contexts', $result->evidence);
    }

    /**
     * Domain separation is asserted, not assumed: two contexts under the same
     * subkey id must not derive the same bytes. If they ever did, every
     * subsystem's key would be every other subsystem's key (ADR-0006).
     */
    public function testMasterKeyDerivationIsDomainSeparatedAndReproducible(): void
    {
        $key = self::masterKey();

        $first = $key->deriveSubKey(SubKeyId::ComplianceDerivationProbe->value, 'cmplprb1');
        $again = $key->deriveSubKey(SubKeyId::ComplianceDerivationProbe->value, 'cmplprb1');
        $other = $key->deriveSubKey(SubKeyId::ComplianceDerivationProbe->value, 'cmplprb2');

        self::assertSame($first, $again);
        self::assertNotSame($first, $other);
        self::assertSame(32, strlen($first));
    }

    /**
     * The verifier catches only SodiumException, which is sound exactly as long
     * as its two KDF contexts stay eight bytes: a context of any other length
     * makes MasterKey::deriveSubKey() throw InvalidArgumentException, and that
     * would escape a boot-time compliance check. The invariant is asserted here
     * so shortening a constant fails a test rather than a boot.
     */
    public function testDerivationContextsAreTheLengthLibsodiumRequires(): void
    {
        $verifier = new ReflectionClass(RuntimeVerifier::class);

        foreach (['DERIVATION_CONTEXT', 'DERIVATION_ALT_CONTEXT'] as $name) {
            $value = $verifier->getConstant($name);

            self::assertIsString($value, "{$name} must be a string context");
            self::assertSame(
                SODIUM_CRYPTO_KDF_CONTEXTBYTES,
                strlen($value),
                "{$name} must be exactly SODIUM_CRYPTO_KDF_CONTEXTBYTES bytes",
            );
        }
    }

    /**
     * The probe must not borrow a live subsystem's key material: deriving under
     * an id another subsystem owns would hand the verifier that subsystem's real
     * key. Its own id keeps the derived bytes unrelated to anything sealed.
     */
    public function testDerivationProbeUsesAnIdNoSubsystemSealsUnder(): void
    {
        $key = self::masterKey();

        $probe = $key->deriveSubKey(SubKeyId::ComplianceDerivationProbe->value, 'cmplprb1');
        $encryption = $key->deriveSubKey(SubKeyId::Encryption->value, 'cmplprb1');

        self::assertNotSame($probe, $encryption);
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

    public function testAuditLoggingInactiveSkippedWhenNoFrameworkRequiresIt(): void
    {
        // When audit logging is inactive AND no framework mandates tamper-evident
        // audit, the check must SKIP, not FAIL: an application with no audit
        // requirement should not carry a permanent failing check.
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(tamperEvidentAudit: false),
            auditLogActive: false,
        );

        $results = $verifier->verify();
        $auditResult = $this->findResult($results, 'runtime.audit_logging');

        self::assertSame(CheckStatus::Skip, $auditResult->status);
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

    /**
     * The defect, stated as a test.
     *
     * The default deployment encrypts with {@see SodiumCipherSuite} —
     * XChaCha20-Poly1305 and crypto_secretbox, neither FIPS 140 approved — on a
     * platform whose OpenSSL offers AES-256-GCM and HMAC-SHA-256 like every
     * other mainstream build. The old check asked only the second question and
     * returned Pass, certifying approved cryptography for a process that
     * performs none. Whatever this now returns, it must not be Pass.
     */
    public function testFipsDoesNotPassOnTheDefaultSodiumSuite(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(),
            activeCipherSuite: new SodiumCipherSuite(),
        );

        $result = $this->findResult($verifier->verify(), 'runtime.fips_mode');

        self::assertNotSame(CheckStatus::Pass, $result->status);
        self::assertSame(CheckStatus::Skip, $result->status);
        self::assertStringContainsString('sodium', $result->message);
        self::assertStringContainsString('not FIPS 140 approved', $result->message);
    }

    /**
     * Availability of the approved algorithms is not, on its own, ever enough:
     * the same platform that fails this check would have passed the old one,
     * because the only thing that changed between the two cases is which suite
     * the deployment encrypts with.
     */
    public function testFipsVerdictFollowsTheBoundSuiteNotThePlatform(): void
    {
        $profile = $this->createProfile();

        $sodium = $this->findResult(
            new RuntimeVerifier(profile: $profile, activeCipherSuite: new SodiumCipherSuite())->verify(),
            'runtime.fips_mode',
        );
        $aes = $this->findResult(
            new RuntimeVerifier(profile: $profile, activeCipherSuite: new AesGcmCipherSuite())->verify(),
            'runtime.fips_mode',
        );

        // "not FIPS 140 approved" is said of the ALGORITHM, and only ever about a
        // suite outside the accept list. The AES-GCM run may still fall short of a
        // pass — on an unvalidated module it does — but never for that reason.
        self::assertSame(CheckStatus::Skip, $sodium->status);
        self::assertStringContainsString('not FIPS 140 approved', $sodium->message);
        self::assertStringNotContainsString('not FIPS 140 approved', $aes->message);
    }

    /**
     * A profile that requires encryption, with nothing bound to perform it, is
     * the one FIPS outcome that must refuse a strict-mode boot: the deployment
     * is obliged to encrypt and no cipher runs at all.
     */
    public function testFipsFailsWhenEncryptionIsRequiredAndNoSuiteIsBound(): void
    {
        $verifier = new RuntimeVerifier(
            profile: $this->createProfile(encryptionAtRest: true),
            activeCipherSuite: null,
        );

        $result = $this->findResult($verifier->verify(), 'runtime.fips_mode');

        self::assertSame(CheckStatus::Fail, $result->status);
        self::assertNotEmpty($result->remediations);
    }

    /**
     * The AES-GCM suite states the deployment's intent to be assessed against
     * FIPS, so this is the branch that is allowed to pass — but only when the
     * module executing AES-256-GCM is itself in FIPS mode. On an ordinary test
     * runner it is not, and the honest answer is that FIPS is not established.
     */
    public function testFipsPassesOnlyWhenTheModuleRunningAesGcmIsValidated(): void
    {
        $suite = new AesGcmCipherSuite();

        $result = $this->findResult(
            new RuntimeVerifier(profile: $this->createProfile(), activeCipherSuite: $suite)->verify(),
            'runtime.fips_mode',
        );

        $validated = !$suite->isUsingSodium() && FipsValidator::verify()->compliant;

        if ($validated) {
            self::assertSame(CheckStatus::Pass, $result->status);
            self::assertContains('cipher_suite: aes-gcm', $result->evidence);
            self::assertContains('backend: openssl', $result->evidence);

            return;
        }

        self::assertSame(CheckStatus::Skip, $result->status);
        self::assertStringContainsString('FIPS is not established', $result->message);
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

    /**
     * A real key: the derivation check runs the KDF against whatever it is
     * given, so a double would only prove that a double was passed.
     */
    private static function masterKey(): MasterKey
    {
        return MasterKey::fromHex(str_repeat('a1', 32));
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
