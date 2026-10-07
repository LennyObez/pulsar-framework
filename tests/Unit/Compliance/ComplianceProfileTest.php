<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfile;

#[CoversClass(ComplianceProfile::class)]
final class ComplianceProfileTest extends TestCase
{
    /**
     * @param list<ComplianceFramework> $frameworks
     */
    private function createProfile(
        array $frameworks = [],
        string $mfaRequirement = 'none',
        bool $encryptionAtRest = false,
        bool $encryptionInTransit = false,
    ): ComplianceProfile {
        return new ComplianceProfile(
            enabledFrameworks: $frameworks,
            passwordMinLength: 8,
            sessionIdleTimeout: 900,
            breachNotificationHours: 72,
            auditRetentionDays: 365,
            dataRetentionDays: 365,
            mfaRequirement: $mfaRequirement,
            encryptionAtRest: $encryptionAtRest,
            encryptionInTransit: $encryptionInTransit,
            tamperEvidentAudit: false,
            explicitConsent: false,
            consentWithdrawal: false,
            individualNotification: false,
            breachRegister: false,
        );
    }

    #[DataProvider('mfaRequirementProvider')]
    public function testRequiresMfa(string $mfaRequirement, bool $expected): void
    {
        $profile = $this->createProfile(mfaRequirement: $mfaRequirement);
        self::assertSame($expected, $profile->requiresMfa());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function mfaRequirementProvider(): iterable
    {
        yield 'none' => ['none', false];
        yield 'always' => ['always', true];
        yield 'privileged' => ['privileged', true];
        yield 'sensitive-data' => ['sensitive-data', true];
    }

    #[DataProvider('universalMfaProvider')]
    public function testRequiresUniversalMfa(string $mfaRequirement, bool $expected): void
    {
        $profile = $this->createProfile(mfaRequirement: $mfaRequirement);
        self::assertSame($expected, $profile->requiresUniversalMfa());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function universalMfaProvider(): iterable
    {
        yield 'none' => ['none', false];
        yield 'always' => ['always', true];
        yield 'privileged' => ['privileged', false];
        yield 'sensitive-data' => ['sensitive-data', false];
    }

    #[DataProvider('encryptionProvider')]
    public function testRequiresEncryption(bool $atRest, bool $inTransit, bool $expected): void
    {
        $profile = $this->createProfile(
            encryptionAtRest: $atRest,
            encryptionInTransit: $inTransit,
        );
        self::assertSame($expected, $profile->requiresEncryption());
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function encryptionProvider(): iterable
    {
        yield 'neither' => [false, false, false];
        yield 'at rest only' => [true, false, true];
        yield 'in transit only' => [false, true, true];
        yield 'both' => [true, true, true];
    }

    public function testHasFramework(): void
    {
        $profile = $this->createProfile(
            frameworks: [ComplianceFramework::PciDss, ComplianceFramework::Hipaa],
        );

        self::assertTrue($profile->hasFramework(ComplianceFramework::PciDss));
        self::assertTrue($profile->hasFramework(ComplianceFramework::Hipaa));
        self::assertFalse($profile->hasFramework(ComplianceFramework::Gdpr));
    }

    public function testHasFrameworkEmptyList(): void
    {
        $profile = $this->createProfile(frameworks: []);

        self::assertFalse($profile->hasFramework(ComplianceFramework::PciDss));
        self::assertFalse($profile->hasFramework(ComplianceFramework::Gdpr));
    }

    public function testAllPropertiesAccessible(): void
    {
        $profile = new ComplianceProfile(
            enabledFrameworks: [ComplianceFramework::Gdpr],
            passwordMinLength: 14,
            sessionIdleTimeout: 600,
            breachNotificationHours: 24,
            auditRetentionDays: 2555,
            dataRetentionDays: 1825,
            mfaRequirement: 'always',
            encryptionAtRest: true,
            encryptionInTransit: true,
            tamperEvidentAudit: true,
            explicitConsent: true,
            consentWithdrawal: true,
            individualNotification: true,
            breachRegister: true,
        );

        self::assertSame([ComplianceFramework::Gdpr], $profile->enabledFrameworks);
        self::assertSame(14, $profile->passwordMinLength);
        self::assertSame(600, $profile->sessionIdleTimeout);
        self::assertSame(24, $profile->breachNotificationHours);
        self::assertSame(2555, $profile->auditRetentionDays);
        self::assertSame(1825, $profile->dataRetentionDays);
        self::assertSame('always', $profile->mfaRequirement);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertTrue($profile->explicitConsent);
        self::assertTrue($profile->consentWithdrawal);
        self::assertTrue($profile->individualNotification);
        self::assertTrue($profile->breachRegister);
    }
}
