<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Compliance\Concern\FrameworkRequirements;
use Pulsar\Compliance\Concern\SwiftCspRequirements;

#[CoversClass(SwiftCspRequirements::class)]
final class SwiftCspRequirementsTest extends TestCase
{
    private SwiftCspRequirements $requirements;

    protected function setUp(): void
    {
        $this->requirements = FrameworkRequirements::swiftCsp();
    }

    #[Test]
    public function passwordMinLengthIs12(): void
    {
        self::assertSame(12, $this->requirements->passwordMinLength());
    }

    #[Test]
    public function sessionIdleIs15Minutes(): void
    {
        self::assertSame(900, $this->requirements->sessionIdleTimeout());
    }

    #[Test]
    public function mfaIsAlways(): void
    {
        self::assertSame('always', $this->requirements->mfaRequirement());
    }

    #[Test]
    public function requiresEncryptionAtRestAndInTransit(): void
    {
        self::assertTrue($this->requirements->requiresEncryptionAtRest());
        self::assertTrue($this->requirements->requiresEncryptionInTransit());
    }

    #[Test]
    public function requiresTamperEvidentAudit(): void
    {
        self::assertTrue($this->requirements->requiresTamperEvidentAudit());
    }

    #[Test]
    public function auditRetentionIs7Years(): void
    {
        self::assertSame(2555, $this->requirements->auditRetentionDays());
    }

    #[Test]
    public function dataRetentionIs7Years(): void
    {
        self::assertSame(2555, $this->requirements->dataRetentionDays());
    }

    #[Test]
    public function breachNotificationHoursIsNull(): void
    {
        self::assertNull($this->requirements->breachNotificationHours());
    }

    #[Test]
    public function requiresBreachRegisterNotIndividualNotification(): void
    {
        self::assertTrue($this->requirements->requiresBreachRegister());
        self::assertFalse($this->requirements->requiresIndividualNotification());
    }

    #[Test]
    public function profileResolverIntegratesSwiftCspCorrectly(): void
    {
        $resolver = new ComplianceProfileResolver();
        $profile = $resolver->resolve([ComplianceFramework::SwiftCsp]);

        self::assertSame(12, $profile->passwordMinLength);
        self::assertSame(900, $profile->sessionIdleTimeout);
        self::assertSame('always', $profile->mfaRequirement);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertSame(2555, $profile->auditRetentionDays);
        self::assertSame(2555, $profile->dataRetentionDays);
        self::assertTrue($profile->breachRegister);
        self::assertFalse($profile->individualNotification);
    }
}
