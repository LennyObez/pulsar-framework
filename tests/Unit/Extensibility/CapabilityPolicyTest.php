<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;

use function in_array;

#[CoversClass(CapabilityPolicy::class)]
final class CapabilityPolicyTest extends TestCase
{
    private CapabilityPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = CapabilityPolicy::defaults();
    }

    #[Test]
    public function coreHasAllCapabilities(): void
    {
        foreach (ExtensionCapability::cases() as $capability) {
            self::assertTrue(
                $this->policy->allows(TrustTier::Core, $capability),
                "Core should have {$capability->name}",
            );
        }
    }

    #[Test]
    public function verifiedHasAllExceptCryptoKeyAccessAndProcessExec(): void
    {
        $denied = [ExtensionCapability::CryptoKeyAccess, ExtensionCapability::ProcessExec];

        foreach (ExtensionCapability::cases() as $capability) {
            if (in_array($capability, $denied, true)) {
                self::assertFalse(
                    $this->policy->allows(TrustTier::Verified, $capability),
                    "Verified should NOT have {$capability->name}",
                );
            } else {
                self::assertTrue(
                    $this->policy->allows(TrustTier::Verified, $capability),
                    "Verified should have {$capability->name}",
                );
            }
        }
    }

    #[Test]
    public function communityHasLimitedCapabilities(): void
    {
        $allowed = [
            ExtensionCapability::ContainerRead,
            ExtensionCapability::ContainerWrite,
            ExtensionCapability::RouteRegister,
            ExtensionCapability::CryptoOperations,
            ExtensionCapability::CommandRegister,
            ExtensionCapability::AuditWrite,
        ];

        foreach (ExtensionCapability::cases() as $capability) {
            if (in_array($capability, $allowed, true)) {
                self::assertTrue(
                    $this->policy->allows(TrustTier::Community, $capability),
                    "Community should have {$capability->name}",
                );
            } else {
                self::assertFalse(
                    $this->policy->allows(TrustTier::Community, $capability),
                    "Community should NOT have {$capability->name}",
                );
            }
        }
    }

    #[Test]
    public function untrustedHasOnlyContainerRead(): void
    {
        self::assertTrue($this->policy->allows(TrustTier::Untrusted, ExtensionCapability::ContainerRead));

        foreach (ExtensionCapability::cases() as $capability) {
            if ($capability === ExtensionCapability::ContainerRead) {
                continue;
            }
            self::assertFalse(
                $this->policy->allows(TrustTier::Untrusted, $capability),
                "Untrusted should NOT have {$capability->name}",
            );
        }
    }

    #[Test]
    public function grantedCapabilitiesReturnsCoreCapabilities(): void
    {
        $granted = $this->policy->grantedCapabilities(TrustTier::Core);

        self::assertSame(ExtensionCapability::cases(), $granted);
    }

    #[Test]
    public function grantedCapabilitiesReturnsCommunityCapabilities(): void
    {
        $granted = $this->policy->grantedCapabilities(TrustTier::Community);

        self::assertCount(6, $granted);
        self::assertContains(ExtensionCapability::ContainerRead, $granted);
        self::assertContains(ExtensionCapability::ContainerWrite, $granted);
        self::assertContains(ExtensionCapability::RouteRegister, $granted);
        self::assertContains(ExtensionCapability::CryptoOperations, $granted);
        self::assertContains(ExtensionCapability::CommandRegister, $granted);
        self::assertContains(ExtensionCapability::AuditWrite, $granted);
    }

    #[Test]
    public function grantedCapabilitiesReturnsUntrustedCapabilities(): void
    {
        $granted = $this->policy->grantedCapabilities(TrustTier::Untrusted);

        self::assertCount(1, $granted);
        self::assertSame([ExtensionCapability::ContainerRead], $granted);
    }

    #[Test]
    public function customPolicyWithExplicitGrants(): void
    {
        $policy = new CapabilityPolicy([
            TrustTier::Community->value => [
                ExtensionCapability::ContainerRead,
                ExtensionCapability::DatabaseRaw,
            ],
        ]);

        self::assertTrue($policy->allows(TrustTier::Community, ExtensionCapability::ContainerRead));
        self::assertTrue($policy->allows(TrustTier::Community, ExtensionCapability::DatabaseRaw));
        self::assertFalse($policy->allows(TrustTier::Community, ExtensionCapability::ContainerWrite));
    }

    #[Test]
    public function customPolicyUnlistedTierHasNoCapabilities(): void
    {
        $policy = new CapabilityPolicy([
            TrustTier::Core->value => ExtensionCapability::cases(),
        ]);

        self::assertTrue($policy->allows(TrustTier::Core, ExtensionCapability::ContainerRead));
        self::assertFalse($policy->allows(TrustTier::Untrusted, ExtensionCapability::ContainerRead));
    }
}
