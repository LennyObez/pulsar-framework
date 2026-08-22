<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ComplianceProfileResolver;
use Pulsar\Compliance\Concern\FrameworkRequirements;
use Pulsar\Compliance\Concern\NistCsfRequirements;

#[CoversClass(NistCsfRequirements::class)]
#[CoversClass(FrameworkRequirements::class)]
final class NistCsfRequirementsTest extends TestCase
{
    private NistCsfRequirements $requirements;

    protected function setUp(): void
    {
        $this->requirements = FrameworkRequirements::nistCsf();
    }

    #[Test]
    public function passwordMinLengthIsEight(): void
    {
        self::assertSame(8, $this->requirements->passwordMinLength());
    }

    #[Test]
    public function sessionIdleTimeoutIsNull(): void
    {
        self::assertNull($this->requirements->sessionIdleTimeout());
    }

    #[Test]
    public function mfaRequirementIsPrivileged(): void
    {
        self::assertSame('privileged', $this->requirements->mfaRequirement());
    }

    #[Test]
    public function auditRetentionIsOneYear(): void
    {
        self::assertSame(365, $this->requirements->auditRetentionDays());
    }

    #[Test]
    public function tamperEvidentAuditRequired(): void
    {
        self::assertTrue($this->requirements->requiresTamperEvidentAudit());
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
    public function dataRetentionIsOneYear(): void
    {
        self::assertSame(365, $this->requirements->dataRetentionDays());
    }

    #[Test]
    public function breachNotificationHasNoFixedDeadline(): void
    {
        self::assertNull($this->requirements->breachNotificationHours());
    }

    #[Test]
    public function resolverHandlesNistCsfFramework(): void
    {
        $resolver = new ComplianceProfileResolver();
        $profile = $resolver->resolve([ComplianceFramework::NistCsf]);

        self::assertSame(8, $profile->passwordMinLength);
        self::assertSame('privileged', $profile->mfaRequirement);
        self::assertSame(365, $profile->auditRetentionDays);
        self::assertTrue($profile->tamperEvidentAudit);
        self::assertTrue($profile->encryptionAtRest);
        self::assertTrue($profile->encryptionInTransit);
    }

    #[Test]
    public function nistCsfPlusPciDssMergesCorrectly(): void
    {
        $resolver = new ComplianceProfileResolver();
        $profile = $resolver->resolve([
            ComplianceFramework::NistCsf,
            ComplianceFramework::PciDss,
        ]);

        // PCI-DSS password (12) wins over NIST (8)
        self::assertSame(12, $profile->passwordMinLength);
        // PCI-DSS session idle (900) wins as only specified
        self::assertSame(900, $profile->sessionIdleTimeout);
        // Both require tamper-evident
        self::assertTrue($profile->tamperEvidentAudit);
        // Both require encryption
        self::assertTrue($profile->encryptionAtRest);
    }
}
