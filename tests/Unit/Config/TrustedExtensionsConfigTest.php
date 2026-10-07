<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Exception\ConfigException;
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

    /**
     * A capability name the framework does not have is refused, not filtered.
     *
     * It used to be filtered out silently. The direction is safe — the
     * extension gets less, never more — and that is exactly what made it
     * invisible: `config/extensions.php` read as a grant that had been made and
     * behaved at runtime as a grant that had not. ADR-0023 calls that file the
     * auditable record of what third-party code was granted, and a record that
     * can say one thing and mean another is not one.
     */
    #[Test]
    public function anUnknownCapabilityNameIsRefusedRatherThanFiltered(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('NonExistent');

        (void) TrustedExtensionsConfig::fromArray([
            'acme/ext' => [
                'tier' => 'community',
                'additional_capabilities' => ['DatabaseRaw', 'NonExistent'],
            ],
        ]);
    }

    /**
     * The message names the capabilities that DO exist, because the mistake is
     * almost always a spelling and the list is short and closed.
     */
    #[Test]
    public function theRefusalNamesTheCapabilitiesThatExist(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('DatabaseRaw');

        (void) TrustedExtensionsConfig::fromArray([
            'acme/ext' => ['tier' => 'community', 'additional_capabilities' => ['Database_Raw']],
        ]);
    }

    #[Test]
    public function aNonStringCapabilityIsRefused(): void
    {
        $this->expectException(ConfigException::class);

        (void) TrustedExtensionsConfig::fromArray([
            'acme/ext' => ['tier' => 'community', 'additional_capabilities' => [42]],
        ]);
    }

    #[Test]
    public function aCapabilityListThatIsNotAListIsRefused(): void
    {
        $this->expectException(ConfigException::class);

        (void) TrustedExtensionsConfig::fromArray([
            'acme/ext' => ['tier' => 'community', 'additional_capabilities' => 'DatabaseRaw'],
        ]);
    }

    #[Test]
    public function additionalCapabilitiesReturnsEmptyForUnknownExtension(): void
    {
        $config = TrustedExtensionsConfig::fromArray([]);

        self::assertSame([], $config->additionalCapabilities('unknown/ext'));
    }

    /**
     * An unrecognised tier is refused for the same reason as an unrecognised
     * capability: it used to become Community without a word, so a deployment
     * whose operator typed `verifed` ran a bundled product extension at the
     * wrong tier and reported the difference only as denials nobody could trace
     * back to the file.
     */
    #[Test]
    public function anUnknownTierIsRefusedRatherThanLoweredToCommunity(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('invalid');

        (void) TrustedExtensionsConfig::fromArray(['acme/ext' => ['tier' => 'invalid']]);
    }

    #[Test]
    public function theTierRefusalNamesTheTiersThatExist(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('verified');

        (void) TrustedExtensionsConfig::fromArray(['acme/ext' => ['tier' => 'verifed']]);
    }

    /**
     * An absent tier still means Community, which is the documented default
     * rather than a failure to understand what was written.
     */
    #[Test]
    public function anAbsentTierStillDefaultsToCommunity(): void
    {
        $config = TrustedExtensionsConfig::fromArray(['acme/ext' => []]);

        self::assertSame(TrustTier::Community, $config->effectiveTier('acme/ext', TrustTier::Core));
    }

    /**
     * An entry that is not shaped like an entry used to be skipped, which
     * capped that extension at Community silently — the same failure as an
     * unknown tier, reached by a different typo.
     */
    #[Test]
    public function aMalformedEntryIsRefusedRatherThanSkipped(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('pulsar/payments');

        (void) TrustedExtensionsConfig::fromArray(['pulsar/payments' => 'core']);
    }

    /**
     * The file the framework ships is itself valid, which is the one case that
     * has to keep working.
     */
    #[Test]
    public function theShippedAllowListParses(): void
    {
        /** @var array{trusted_extensions: array<array-key, mixed>} $config */
        $config = require __DIR__ . '/../../../config/extensions.php';

        $trusted = TrustedExtensionsConfig::fromArray($config['trusted_extensions']);

        self::assertSame(TrustTier::Core, $trusted->effectiveTier('pulsar/auth', TrustTier::Core));
        self::assertSame(TrustTier::Verified, $trusted->effectiveTier('pulsar/tickets', TrustTier::Core));
    }
}
