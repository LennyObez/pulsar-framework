<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Marketplace;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Exception\ConfigException;
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
        self::assertFalse($config->verifySignatures);
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
        self::assertFalse($config->verifySignatures);
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
        ]);

        self::assertSame('https://marketplace.pulsarphp.com/api/v1', $config->registryUrl);
        self::assertFalse($config->autoUpdate);
    }

    /**
     * No installer, loader or verifier in the framework reads this flag. Accepting
     * `true` would hand an operator — and any auditor reading their config — a
     * signature check that never runs, so construction refuses it outright.
     */
    #[Test]
    public function constructingWithSignatureVerificationEnabledIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('marketplace.verify_signatures');

        new MarketplaceConfig(verifySignatures: true);
    }

    #[Test]
    public function fromArrayRefusesSignatureVerification(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('marketplace.verify_signatures');

        (void) MarketplaceConfig::fromArray(['verify_signatures' => true]);
    }

    /**
     * Coercing a non-bool down to false would be the same silence in a different
     * shape: the operator asked for verification and would be told nothing.
     *
     * @param mixed $value Raw `verify_signatures` value as it would arrive from config
     */
    #[Test]
    #[DataProvider('nonBooleanSignatureValues')]
    public function fromArrayRefusesNonBooleanSignatureValues(mixed $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('marketplace.verify_signatures');

        (void) MarketplaceConfig::fromArray(['verify_signatures' => $value]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonBooleanSignatureValues(): iterable
    {
        yield 'int one' => [1];
        yield 'string true' => ['true'];
        yield 'string yes' => ['yes'];
        yield 'string one' => ['1'];
    }

    #[Test]
    public function fromArrayAcceptsAnExplicitlyDisabledSignatureCheck(): void
    {
        $config = MarketplaceConfig::fromArray(['verify_signatures' => false]);

        self::assertFalse($config->verifySignatures);
    }
}
