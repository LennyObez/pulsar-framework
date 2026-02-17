<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Compliance\Concern\CcpaRequirements;
use Pulsar\Compliance\Concern\FrameworkRequirements;

#[CoversClass(CcpaRequirements::class)]
#[CoversClass(FrameworkRequirements::class)]
final class CcpaRequirementsTest extends TestCase
{
    private CcpaRequirements $requirements;

    protected function setUp(): void
    {
        $this->requirements = FrameworkRequirements::ccpa();
    }

    #[Test]
    public function auditRetentionIsTwoYears(): void
    {
        self::assertSame(730, $this->requirements->auditRetentionDays());
    }

    #[Test]
    public function dataRetentionIsTwoYears(): void
    {
        self::assertSame(730, $this->requirements->dataRetentionDays());
    }

    #[Test]
    public function tamperEvidentAuditNotRequired(): void
    {
        self::assertFalse($this->requirements->requiresTamperEvidentAudit());
    }

    #[Test]
    public function explicitConsentRequired(): void
    {
        self::assertTrue($this->requirements->requiresExplicitConsent());
    }

    #[Test]
    public function consentWithdrawalRequired(): void
    {
        self::assertTrue($this->requirements->requiresConsentWithdrawal());
    }

    #[Test]
    public function encryptionAtRestRequired(): void
    {
        self::assertTrue($this->requirements->requiresEncryptionAtRest());
    }

    #[Test]
    public function encryptionInTransitRequired(): void
    {
        self::assertTrue($this->requirements->requiresEncryptionInTransit());
    }

    #[Test]
    public function breachNotificationHasNoFixedDeadline(): void
    {
        self::assertNull($this->requirements->breachNotificationHours());
    }

    #[Test]
    public function resolverHandlesCcpaFramework(): void
    {
        $resolver = new ComplianceProfileResolver();
        $profile = $resolver->resolve([ComplianceFramework::Ccpa]);

        self::assertSame(730, $profile->auditRetentionDays);
        self::assertSame(730, $profile->dataRetentionDays);
        self::assertTrue($profile->explicitConsent);
        self::assertTrue($profile->consentWithdrawal);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
    }

    #[Test]
    public function ccpaPlusGdprMergesCorrectly(): void
    {
        $resolver = new ComplianceProfileResolver();
        $profile = $resolver->resolve([
            ComplianceFramework::Ccpa,
            ComplianceFramework::Gdpr,
        ]);

        // GDPR breach notification (72h) wins over CCPA (null) as "most specified"
        self::assertSame(72, $profile->breachNotificationHours);
        // Both require consent
        self::assertTrue($profile->explicitConsent);
        self::assertTrue($profile->consentWithdrawal);
        // GDPR requires individual notification; CCPA doesn't implement the interface
        self::assertTrue($profile->individualNotification);
    }
}
