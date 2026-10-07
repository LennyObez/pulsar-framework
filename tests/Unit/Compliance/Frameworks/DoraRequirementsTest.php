<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Compliance\Concern\DoraRequirements;
use Pulsar\Compliance\Concern\FrameworkRequirements;

#[CoversClass(DoraRequirements::class)]
final class DoraRequirementsTest extends TestCase
{
    private DoraRequirements $requirements;

    protected function setUp(): void
    {
        $this->requirements = FrameworkRequirements::dora();
    }

    #[Test]
    public function breachNotificationIs4Hours(): void
    {
        self::assertSame(4, $this->requirements->breachNotificationHours());
    }

    #[Test]
    public function sessionIdleIs5Minutes(): void
    {
        self::assertSame(300, $this->requirements->sessionIdleTimeout());
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
    public function auditRetentionIs5Years(): void
    {
        self::assertSame(1825, $this->requirements->auditRetentionDays());
    }

    #[Test]
    public function requiresBreachRegisterNotIndividualNotification(): void
    {
        self::assertTrue($this->requirements->requiresBreachRegister());
        self::assertFalse($this->requirements->requiresIndividualNotification());
    }

    #[Test]
    public function profileResolverIntegratesDoraCorrectly(): void
    {
        $resolver = new ComplianceProfileResolver();
        $profile = $resolver->resolve([ComplianceFramework::Dora]);

        self::assertSame(4, $profile->breachNotificationHours);
        self::assertSame(300, $profile->sessionIdleTimeout);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertTrue($profile->breachRegister);
        self::assertSame(1825, $profile->auditRetentionDays);
    }
}
