<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\TrustedExtensionsConfig;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;

#[CoversClass(TrustedExtensionsConfig::class)]
final class TrustedExtensionsConfigTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesConfig(): void
    {
        $config = TrustedExtensionsConfig::fromArray([
            'pulsar/admin' => ['tier' => 'core'],
            'acme/analytics' => ['tier' => 'verified'],
        ]);

        self::assertSame(TrustTier::Core, $config->effectiveTier('pulsar/admin', TrustTier::Core));
        self::assertSame(TrustTier::Verified, $config->effectiveTier('acme/analytics', TrustTier::Verified));
    }

    #[Test]
    public function effectiveTierDefaultsToCommunityForUnknownExtension(): void
    {
        $config = TrustedExtensionsConfig::fromArray([]);

        self::assertSame(TrustTier::Community, $config->effectiveTier('unknown/ext', TrustTier::Core));
    }

    #[Test]
    public function effectiveTierReturnsMinOfRequestedAndAllowed(): void
    {
        $config = TrustedExtensionsConfig::fromArray([
            'acme/ext' => ['tier' => 'community'],
        ]);

        // Extension requests Verified, host allows Community → effective is Community
        self::assertSame(TrustTier::Community, $config->effectiveTier('acme/ext', TrustTier::Verified));
    }

    #[Test]
    public function effectiveTierUsesRequestedWhenLowerThanAllowed(): void
    {
        $config = TrustedExtensionsConfig::fromArray([
            'acme/ext' => ['tier' => 'verified'],
        ]);

        // Extension requests Community, host allows Verified → effective is Community
        self::assertSame(TrustTier::Community, $config->effectiveTier('acme/ext', TrustTier::Community));
    }

    #[Test]
    public function effectiveTierUsesCoreWhenBothAreCoreExplicit(): void
    {
        $config = TrustedExtensionsConfig::fromArray([
            'pulsar/studio' => ['tier' => 'core'],
        ]);

        self::assertSame(TrustTier::Core, $config->effectiveTier('pulsar/studio', TrustTier::Core));
    }

    #[Test]
    public function additionalCapabilitiesReturnsEmptyByDefault(): void
    {
        $config = TrustedExtensionsConfig::fromArray([
            'acme/ext' => ['tier' => 'community'],
        ]);

        self::assertSame([], $config->additionalCapabilities('acme/ext'));
    }

    #[Test]
    public function additionalCapabilitiesReturnsGrantedCapabilities(): void
    {
        $config = TrustedExtensionsConfig::fromArray([
            'acme/ext' => [
                'tier' => 'community',
                'additional_capabilities' => ['DatabaseRaw', 'NetworkEgress'],
            ],
        ]);

        $capabilities = $config->additionalCapabilities('acme/ext');

        self::assertCount(2, $capabilities);
        self::assertContains(ExtensionCapability::DatabaseRaw, $capabilities);
        self::assertContains(ExtensionCapability::NetworkEgress, $capabilities);
    }

    #[Test]
    public function additionalCapabilitiesIgnoresInvalidCapabilityNames(): void
    {
        $config = TrustedExtensionsConfig::fromArray([
            'acme/ext' => [
                'tier' => 'community',
                'additional_capabilities' => ['DatabaseRaw', 'NonExistent'],
            ],
        ]);

        $capabilities = $config->additionalCapabilities('acme/ext');

        self::assertCount(1, $capabilities);
        self::assertContains(ExtensionCapability::DatabaseRaw, $capabilities);
    }

    #[Test]
    public function additionalCapabilitiesReturnsEmptyForUnknownExtension(): void
    {
        $config = TrustedExtensionsConfig::fromArray([]);

        self::assertSame([], $config->additionalCapabilities('unknown/ext'));
    }

    #[Test]
    public function fromArrayHandlesInvalidTierString(): void
    {
        $config = TrustedExtensionsConfig::fromArray([
            'acme/ext' => ['tier' => 'invalid'],
        ]);

        // Invalid tier string falls back to Community
        self::assertSame(TrustTier::Community, $config->effectiveTier('acme/ext', TrustTier::Core));
    }
}
