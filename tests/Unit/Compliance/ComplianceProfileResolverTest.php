<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceConstraints;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;
use Pulsar\Compliance\ComplianceProfileResolver;

#[CoversClass(ComplianceProfileResolver::class)]
#[CoversClass(ComplianceProfile::class)]
#[CoversClass(ComplianceConstraints::class)]
final class ComplianceProfileResolverTest extends TestCase
{
    private ComplianceProfileResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ComplianceProfileResolver();
    }

    // --- Empty frameworks → defaults ---

    #[Test]
    public function emptyFrameworksReturnsDefaults(): void
    {
        $profile = $this->resolver->resolve([]);

        self::assertSame([], $profile->enabledFrameworks);
        self::assertSame(8, $profile->passwordMinLength);
        self::assertSame(900, $profile->sessionIdleTimeout);
        self::assertSame(72, $profile->breachNotificationHours);
        self::assertSame(365, $profile->auditRetentionDays);
        self::assertSame(365, $profile->dataRetentionDays);
        self::assertSame('none', $profile->mfaRequirement);
        self::assertFalse($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
        self::assertFalse($profile->tamperEvidentAudit);
        self::assertFalse($profile->explicitConsent);
        self::assertFalse($profile->consentWithdrawal);
        self::assertFalse($profile->individualNotification);
        self::assertFalse($profile->breachRegister);
    }

    // --- Single framework ---

    #[Test]
    public function singlePciDssFramework(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::PciDss]);

        self::assertSame(12, $profile->passwordMinLength);
        self::assertSame(900, $profile->sessionIdleTimeout);
        self::assertSame('privileged', $profile->mfaRequirement);
        self::assertSame(365, $profile->auditRetentionDays);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
        self::assertTrue($profile->tamperEvidentAudit);
    }

    #[Test]
    public function singleGdprFramework(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Gdpr]);

        self::assertSame(72, $profile->breachNotificationHours);
        self::assertTrue($profile->explicitConsent);
        self::assertTrue($profile->consentWithdrawal);
        self::assertTrue($profile->individualNotification);
        self::assertTrue($profile->breachRegister);
        self::assertTrue($profile->encryptionAtRest);
    }

    #[Test]
    public function singleHipaaFramework(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Hipaa]);

        self::assertSame(8, $profile->passwordMinLength);
        self::assertSame('sensitive-data', $profile->mfaRequirement);
        self::assertSame(2190, $profile->auditRetentionDays);
        self::assertSame(2190, $profile->dataRetentionDays);
        self::assertSame(1440, $profile->breachNotificationHours);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
        self::assertTrue($profile->individualNotification);
    }

    #[Test]
    public function singlePsd2Framework(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Psd2]);

        self::assertSame(300, $profile->sessionIdleTimeout);
        self::assertSame('always', $profile->mfaRequirement);
        self::assertSame(4, $profile->breachNotificationHours);
        self::assertSame(1825, $profile->auditRetentionDays);
        self::assertTrue($profile->tamperEvidentAudit);
    }

    #[Test]
    public function singleEidasFramework(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Eidas]);

        self::assertSame(3650, $profile->auditRetentionDays);
        self::assertSame(3650, $profile->dataRetentionDays);
        self::assertSame(24, $profile->breachNotificationHours);
        self::assertTrue($profile->tamperEvidentAudit);
    }

    // --- Multi-framework resolution: most restrictive wins ---

    #[Test]
    public function pciDssPlusHipaaPlusGdprProducesStrictestIntersection(): void
    {
        $profile = $this->resolver->resolve([
            ComplianceFramework::PciDss,
            ComplianceFramework::Hipaa,
            ComplianceFramework::Gdpr,
        ]);

        // Password: PCI-DSS=12, HIPAA=8 → 12 (largest)
        self::assertSame(12, $profile->passwordMinLength);

        // Session idle: PCI-DSS=900, others=null → 900 (only specified)
        self::assertSame(900, $profile->sessionIdleTimeout);

        // Breach notification: HIPAA=1440, GDPR=72 → 72 (smallest)
        self::assertSame(72, $profile->breachNotificationHours);

        // Audit retention: PCI-DSS=365, HIPAA=2190, GDPR=365 → 2190 (largest)
        self::assertSame(2190, $profile->auditRetentionDays);

        // Data retention: PCI-DSS=365, HIPAA=2190, GDPR=365 → 2190 (largest)
        self::assertSame(2190, $profile->dataRetentionDays);

        // MFA: PCI-DSS=privileged, HIPAA=sensitive-data → privileged (broader)
        self::assertSame('privileged', $profile->mfaRequirement);

        // Encryption: all require → true
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);

        // Tamper-evident: PCI-DSS=true, HIPAA=true → true
        self::assertTrue($profile->tamperEvidentAudit);

        // Consent: GDPR requires → true
        self::assertTrue($profile->explicitConsent);
        self::assertTrue($profile->consentWithdrawal);

        // Individual notification: HIPAA=true, GDPR=true → true
        self::assertTrue($profile->individualNotification);

        // Breach register: HIPAA=true, GDPR=true → true
        self::assertTrue($profile->breachRegister);
    }

    #[Test]
    public function addingPsd2TightensBreachNotificationAndMfa(): void
    {
        $withoutPsd2 = $this->resolver->resolve([
            ComplianceFramework::PciDss,
            ComplianceFramework::Gdpr,
        ]);

        $withPsd2 = $this->resolver->resolve([
            ComplianceFramework::PciDss,
            ComplianceFramework::Gdpr,
            ComplianceFramework::Psd2,
        ]);

        // PSD2 adds 4-hour breach notification (tighter than GDPR's 72)
        self::assertSame(72, $withoutPsd2->breachNotificationHours);
        self::assertSame(4, $withPsd2->breachNotificationHours);

        // PSD2 adds 'always' MFA (broader than PCI-DSS 'privileged')
        self::assertSame('privileged', $withoutPsd2->mfaRequirement);
        self::assertSame('always', $withPsd2->mfaRequirement);

        // PSD2 adds 300s idle timeout (tighter than PCI-DSS 900s)
        self::assertSame(900, $withoutPsd2->sessionIdleTimeout);
        self::assertSame(300, $withPsd2->sessionIdleTimeout);
    }

    #[Test]
    public function addingEidasExtendsRetention(): void
    {
        $withoutEidas = $this->resolver->resolve([
            ComplianceFramework::PciDss,
            ComplianceFramework::Hipaa,
        ]);

        $withEidas = $this->resolver->resolve([
            ComplianceFramework::PciDss,
            ComplianceFramework::Hipaa,
            ComplianceFramework::Eidas,
        ]);

        // eIDAS adds 3650 days retention (10 years, beyond HIPAA's 6)
        self::assertSame(2190, $withoutEidas->auditRetentionDays);
        self::assertSame(3650, $withEidas->auditRetentionDays);

        self::assertSame(2190, $withoutEidas->dataRetentionDays);
        self::assertSame(3650, $withEidas->dataRetentionDays);
    }

    #[Test]
    public function nis2PlusPsd2ResolvesBreachNotificationToFourHours(): void
    {
        $profile = $this->resolver->resolve([
            ComplianceFramework::Nis2,
            ComplianceFramework::Psd2,
        ]);

        // NIS2=24h, PSD2=4h → 4 (smallest)
        self::assertSame(4, $profile->breachNotificationHours);
    }

    #[Test]
    public function allFrameworksProducesMostRestrictiveAcrossAll(): void
    {
        $profile = $this->resolver->resolve([
            ComplianceFramework::Soc2,
            ComplianceFramework::Hipaa,
            ComplianceFramework::Gdpr,
            ComplianceFramework::PciDss,
            ComplianceFramework::Nis2,
            ComplianceFramework::Iso27001,
            ComplianceFramework::Psd2,
            ComplianceFramework::Eidas,
            ComplianceFramework::Iso42001,
            ComplianceFramework::Hl7Fhir,
        ]);

        // Password: max across all = PCI-DSS 12
        self::assertSame(12, $profile->passwordMinLength);

        // Session idle: min of PCI-DSS=900, PSD2=300 → 300
        self::assertSame(300, $profile->sessionIdleTimeout);

        // Breach notification: min of GDPR=72, NIS2=24, PSD2=4, eIDAS=24, HIPAA=1440 → 4
        self::assertSame(4, $profile->breachNotificationHours);

        // Audit retention: max = eIDAS 3650
        self::assertSame(3650, $profile->auditRetentionDays);

        // Data retention: max = eIDAS 3650
        self::assertSame(3650, $profile->dataRetentionDays);

        // MFA: broadest = PSD2 'always'
        self::assertSame('always', $profile->mfaRequirement);

        // Everything boolean OR'd → all true
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertTrue($profile->explicitConsent);
        self::assertTrue($profile->consentWithdrawal);
        self::assertTrue($profile->individualNotification);
        self::assertTrue($profile->breachRegister);

        // All 10 frameworks present
        self::assertCount(10, $profile->enabledFrameworks);
    }

    // --- ComplianceProfile helper methods ---

    #[Test]
    public function requiresMfaReturnsFalseForNone(): void
    {
        $profile = $this->resolver->resolve([]);

        self::assertFalse($profile->requiresMfa());
    }

    #[Test]
    public function requiresMfaReturnsTrueForAnyScope(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Soc2]);

        self::assertTrue($profile->requiresMfa());
        self::assertFalse($profile->requiresUniversalMfa());
    }

    #[Test]
    public function requiresUniversalMfaReturnsTrueForAlways(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Psd2]);

        self::assertTrue($profile->requiresMfa());
        self::assertTrue($profile->requiresUniversalMfa());
    }

    #[Test]
    public function requiresEncryptionCombinesBothFlags(): void
    {
        $noEncryption = $this->resolver->resolve([]);
        self::assertFalse($noEncryption->encryptionAtRest);

        $withEncryption = $this->resolver->resolve([ComplianceFramework::PciDss]);
        self::assertTrue($withEncryption->requiresEncryption());
    }

    #[Test]
    public function hasFrameworkChecksEnabled(): void
    {
        $profile = $this->resolver->resolve([
            ComplianceFramework::PciDss,
            ComplianceFramework::Gdpr,
        ]);

        self::assertTrue($profile->hasFramework(ComplianceFramework::PciDss));
        self::assertTrue($profile->hasFramework(ComplianceFramework::Gdpr));
        self::assertFalse($profile->hasFramework(ComplianceFramework::Hipaa));
    }

    // --- Iso42001-specific ---

    #[Test]
    public function iso42001AddsAuditRetentionAndTamperEvidence(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Iso42001]);

        self::assertSame(1825, $profile->auditRetentionDays);
        self::assertSame(1825, $profile->dataRetentionDays);
        self::assertTrue($profile->tamperEvidentAudit);
        // ISO 42001 alone doesn't add MFA, encryption, consent
        self::assertSame('none', $profile->mfaRequirement);
        self::assertFalse($profile->encryptionAtRest);
        self::assertFalse($profile->explicitConsent);
    }

    // --- HL7 FHIR-specific ---

    #[Test]
    public function hl7FhirAddsConsentAndAuditRetention(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Hl7Fhir]);

        self::assertSame(2190, $profile->auditRetentionDays);
        self::assertSame(2190, $profile->dataRetentionDays);
        self::assertTrue($profile->explicitConsent);
        self::assertTrue($profile->consentWithdrawal);
    }

    // --- Data provider: MFA scope resolution ---

    /**
     * @return iterable<string, array{list<ComplianceFramework>, string}>
     */
    public static function mfaScopeProvider(): iterable
    {
        yield 'no frameworks → none' => [
            [],
            'none',
        ];

        yield 'SOC 2 only → privileged' => [
            [ComplianceFramework::Soc2],
            'privileged',
        ];

        yield 'HIPAA only → sensitive-data' => [
            [ComplianceFramework::Hipaa],
            'sensitive-data',
        ];

        yield 'PSD2 only → always' => [
            [ComplianceFramework::Psd2],
            'always',
        ];

        yield 'SOC 2 + HIPAA → privileged beats sensitive-data' => [
            [ComplianceFramework::Soc2, ComplianceFramework::Hipaa],
            'privileged',
        ];

        yield 'SOC 2 + PSD2 → always beats privileged' => [
            [ComplianceFramework::Soc2, ComplianceFramework::Psd2],
            'always',
        ];

        yield 'HIPAA + PSD2 → always beats sensitive-data' => [
            [ComplianceFramework::Hipaa, ComplianceFramework::Psd2],
            'always',
        ];
    }

    /**
     * @param list<ComplianceFramework> $frameworks
     */
    #[Test]
    #[DataProvider('mfaScopeProvider')]
    public function mfaScopeResolution(array $frameworks, string $expected): void
    {
        $profile = $this->resolver->resolve($frameworks);

        self::assertSame($expected, $profile->mfaRequirement);
    }

    // --- Data provider: breach notification hours ---

    /**
     * @return iterable<string, array{list<ComplianceFramework>, int}>
     */
    public static function breachNotificationProvider(): iterable
    {
        yield 'no frameworks → default 72' => [
            [],
            72,
        ];

        yield 'GDPR only → 72' => [
            [ComplianceFramework::Gdpr],
            72,
        ];

        yield 'NIS2 only → 24' => [
            [ComplianceFramework::Nis2],
            24,
        ];

        yield 'PSD2 only → 4' => [
            [ComplianceFramework::Psd2],
            4,
        ];

        yield 'GDPR + NIS2 → 24 (smallest)' => [
            [ComplianceFramework::Gdpr, ComplianceFramework::Nis2],
            24,
        ];

        yield 'GDPR + NIS2 + PSD2 → 4 (smallest)' => [
            [ComplianceFramework::Gdpr, ComplianceFramework::Nis2, ComplianceFramework::Psd2],
            4,
        ];

        yield 'PCI-DSS + SOC 2 (no deadline) → default 72' => [
            [ComplianceFramework::PciDss, ComplianceFramework::Soc2],
            72,
        ];
    }

    /**
     * @param list<ComplianceFramework> $frameworks
     */
    #[Test]
    #[DataProvider('breachNotificationProvider')]
    public function breachNotificationResolution(array $frameworks, int $expected): void
    {
        $profile = $this->resolver->resolve($frameworks);

        self::assertSame($expected, $profile->breachNotificationHours);
    }

    // --- Additional framework-specific coverage ---

    #[Test]
    public function doraFinancialRequirements(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Dora]);

        self::assertSame(300, $profile->sessionIdleTimeout);
        self::assertSame(4, $profile->breachNotificationHours);
        self::assertSame(1825, $profile->auditRetentionDays);
        self::assertSame(1825, $profile->dataRetentionDays);
        self::assertSame('privileged', $profile->mfaRequirement);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertTrue($profile->breachRegister);
        self::assertFalse($profile->individualNotification);
    }

    #[Test]
    public function swiftCspStrictestFramework(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::SwiftCsp]);

        self::assertSame(12, $profile->passwordMinLength);
        self::assertSame(900, $profile->sessionIdleTimeout);
        self::assertSame('always', $profile->mfaRequirement);
        self::assertSame(2555, $profile->auditRetentionDays);
        self::assertSame(2555, $profile->dataRetentionDays);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertTrue($profile->breachRegister);
        self::assertFalse($profile->individualNotification);
    }

    #[Test]
    public function ccpaConsentAndRetention(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Ccpa]);

        self::assertSame(730, $profile->auditRetentionDays);
        self::assertSame(730, $profile->dataRetentionDays);
        self::assertTrue($profile->explicitConsent);
        self::assertTrue($profile->consentWithdrawal);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
    }

    #[Test]
    public function nistCsfRequirements(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::NistCsf]);

        self::assertSame(8, $profile->passwordMinLength);
        self::assertSame('privileged', $profile->mfaRequirement);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertSame(365, $profile->auditRetentionDays);
    }

    #[Test]
    public function mdrAuditRetention10Years(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Mdr]);

        self::assertSame(3650, $profile->auditRetentionDays);
        self::assertSame(3650, $profile->dataRetentionDays);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertFalse($profile->encryptionAtRest);
    }

    #[Test]
    public function iso13485QualityRecordRetention(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Iso13485]);

        self::assertSame(3650, $profile->auditRetentionDays);
        self::assertSame(3650, $profile->dataRetentionDays);
        self::assertTrue($profile->tamperEvidentAudit);
    }

    #[Test]
    public function nis2Framework(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Nis2]);

        self::assertSame(8, $profile->passwordMinLength);
        self::assertSame('privileged', $profile->mfaRequirement);
        self::assertSame(24, $profile->breachNotificationHours);
        self::assertSame(1825, $profile->auditRetentionDays);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertTrue($profile->breachRegister);
        self::assertFalse($profile->individualNotification);
    }

    #[Test]
    public function iso27001Framework(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Iso27001]);

        self::assertSame(8, $profile->passwordMinLength);
        self::assertSame('privileged', $profile->mfaRequirement);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertSame(365, $profile->auditRetentionDays);
    }

    #[Test]
    public function soc2Framework(): void
    {
        $profile = $this->resolver->resolve([ComplianceFramework::Soc2]);

        self::assertSame(8, $profile->passwordMinLength);
        self::assertSame('privileged', $profile->mfaRequirement);
        self::assertFalse($profile->tamperEvidentAudit);
        self::assertSame(365, $profile->auditRetentionDays);
    }

    #[DataProvider('singleFrameworkProvider')]
    public function testEachFrameworkProducesValidProfile(ComplianceFramework $framework): void
    {
        $profile = $this->resolver->resolve([$framework]);

        self::assertCount(1, $profile->enabledFrameworks);
        self::assertSame($framework, $profile->enabledFrameworks[0]);
        self::assertGreaterThanOrEqual(8, $profile->passwordMinLength);
        self::assertGreaterThan(0, $profile->sessionIdleTimeout);
        self::assertGreaterThan(0, $profile->breachNotificationHours);
        self::assertGreaterThan(0, $profile->auditRetentionDays);
        self::assertGreaterThan(0, $profile->dataRetentionDays);
        self::assertContains($profile->mfaRequirement, ['none', 'sensitive-data', 'privileged', 'always']);
    }

    /**
     * @return iterable<string, array{ComplianceFramework}>
     */
    public static function singleFrameworkProvider(): iterable
    {
        foreach (ComplianceFramework::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    #[Test]
    public function allSixteenFrameworksCombined(): void
    {
        $all = ComplianceFramework::cases();
        $profile = $this->resolver->resolve($all);

        self::assertCount(18, $profile->enabledFrameworks);
        self::assertSame(12, $profile->passwordMinLength);
        self::assertSame(300, $profile->sessionIdleTimeout);
        self::assertSame(4, $profile->breachNotificationHours);
        self::assertSame(3650, $profile->auditRetentionDays);
        self::assertSame('always', $profile->mfaRequirement);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertTrue($profile->explicitConsent);
        self::assertTrue($profile->consentWithdrawal);
        self::assertTrue($profile->individualNotification);
        self::assertTrue($profile->breachRegister);
    }

    #[Test]
    public function requiresEncryptionFalseWhenBothDisabled(): void
    {
        $profile = new ComplianceProfile(
            enabledFrameworks: [],
            passwordMinLength: 8,
            sessionIdleTimeout: 900,
            breachNotificationHours: 72,
            auditRetentionDays: 365,
            dataRetentionDays: 365,
            mfaRequirement: 'none',
            encryptionAtRest: false,
            encryptionInTransit: false,
            tamperEvidentAudit: false,
            explicitConsent: false,
            consentWithdrawal: false,
            individualNotification: false,
            breachRegister: false,
        );

        self::assertFalse($profile->requiresEncryption());
    }

    #[Test]
    public function constraintsAreAllFalseWithNoFrameworks(): void
    {
        $constraints = $this->resolver->constraints([]);

        self::assertFalse($constraints->sessionIdleTimeout);
        self::assertFalse($constraints->passwordMinLength);
        self::assertFalse($constraints->breachNotificationHours);
        self::assertFalse($constraints->auditRetentionDays);
        self::assertFalse($constraints->dataRetentionDays);
    }

    #[Test]
    public function gdprConstrainsNeitherSessionTimeoutNorPasswordLength(): void
    {
        // GDPR implements no HasAccessControl, so it mandates neither a session
        // idle timeout nor a password floor — the resolver's baseline defaults for
        // those controls must be reported as UNconstrained so enforcement skips them.
        $constraints = $this->resolver->constraints([ComplianceFramework::Gdpr]);

        self::assertFalse($constraints->sessionIdleTimeout, 'GDPR does not mandate a session idle timeout');
        self::assertFalse($constraints->passwordMinLength, 'GDPR does not mandate a password length');
    }

    #[Test]
    public function pciDssConstrainsSessionTimeoutAndPasswordLength(): void
    {
        $constraints = $this->resolver->constraints([ComplianceFramework::PciDss]);

        self::assertTrue($constraints->sessionIdleTimeout, 'PCI-DSS mandates a session idle timeout');
        self::assertTrue($constraints->passwordMinLength, 'PCI-DSS mandates a password length');
    }
}
