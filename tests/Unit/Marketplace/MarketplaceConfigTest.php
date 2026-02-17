<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Marketplace;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Marketplace\MarketplaceConfig;

#[CoversClass(MarketplaceConfig::class)]
final class MarketplaceConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = new MarketplaceConfig();

        self::assertSame('https://marketplace.pulsarphp.com/api/v1', $config->registryUrl);
        self::assertFalse($config->autoUpdate);
        self::assertSame(TrustTier::Community, $config->minimumTrustTier);
        self::assertTrue($config->verifySignatures);
        self::assertSame(3600, $config->cacheLifetimeSeconds);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = MarketplaceConfig::fromArray([
            'registry_url' => 'https://custom.registry.com/v2',
            'auto_update' => true,
            'minimum_trust_tier' => 'verified',
            'verify_signatures' => false,
            'cache_lifetime' => 7200,
        ]);

        self::assertSame('https://custom.registry.com/v2', $config->registryUrl);
        self::assertTrue($config->autoUpdate);
        self::assertSame(TrustTier::Verified, $config->minimumTrustTier);
        self::assertFalse($config->verifySignatures);
        self::assertSame(7200, $config->cacheLifetimeSeconds);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = MarketplaceConfig::fromArray([]);

        self::assertSame('https://marketplace.pulsarphp.com/api/v1', $config->registryUrl);
        self::assertFalse($config->autoUpdate);
        self::assertSame(TrustTier::Community, $config->minimumTrustTier);
    }

    #[Test]
    public function fromArrayWithInvalidTrustTierUsesDefault(): void
    {
        $config = MarketplaceConfig::fromArray([
            'minimum_trust_tier' => 'invalid',
        ]);

        self::assertSame(TrustTier::Community, $config->minimumTrustTier);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $config = MarketplaceConfig::fromArray([
            'registry_url' => 42,
            'auto_update' => 'yes',
            'verify_signatures' => 1,
        ]);

        self::assertSame('https://marketplace.pulsarphp.com/api/v1', $config->registryUrl);
        self::assertFalse($config->autoUpdate);
        self::assertTrue($config->verifySignatures);
    }
}
