<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Exception\ManifestException;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\Manifest\ProvidesConfig;
use Pulsar\Extensibility\Manifest\PulsarVersionConfig;
use Pulsar\Extensibility\Manifest\RequiresConfig;
use Pulsar\Extensibility\TrustTier;

#[CoversClass(ExtensionManifest::class)]
#[CoversClass(ProvidesConfig::class)]
#[CoversClass(PulsarVersionConfig::class)]
#[CoversClass(RequiresConfig::class)]
final class ExtensionManifestTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesManifest(): void
    {
        $data = [
            'name' => 'vendor/test-extension',
            'version' => '1.0.0',
            'extension_class' => 'Vendor\\Test\\TestExtension',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'description' => 'A test extension',
        ];

        $manifest = ExtensionManifest::fromArray($data, '/path/to/extension');

        self::assertSame('vendor/test-extension', $manifest->name);
        self::assertSame('1.0.0', $manifest->version);
        self::assertSame('Vendor\\Test\\TestExtension', $manifest->extensionClass);
        self::assertSame('A test extension', $manifest->description);
        self::assertSame('/path/to/extension', $manifest->path);
    }

    #[Test]
    public function fromArrayThrowsOnMissingName(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('Missing required field "name"');

        $_ = ExtensionManifest::fromArray([
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
        ]);
    }

    #[Test]
    public function fromArrayThrowsOnMissingVersion(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('Missing required field "version"');

        $_ = ExtensionManifest::fromArray([
            'name' => 'test',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
        ]);
    }

    #[Test]
    public function fromArrayThrowsOnMissingExtensionClass(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('Missing required field "extension_class"');

        $_ = ExtensionManifest::fromArray([
            'name' => 'test',
            'version' => '1.0.0',
        ]);
    }

    #[Test]
    public function fromArrayThrowsOnInvalidVersion(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('Invalid version format');

        $_ = ExtensionManifest::fromArray([
            'name' => 'test',
            'version' => 'invalid',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
        ]);
    }

    #[Test]
    public function fromArrayParsesPulsarConfig(): void
    {
        $data = [
            'name' => 'test',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => [
                'min_version' => '0.2.0',
                'max_version' => '1.0.0',
            ],
        ];

        $manifest = ExtensionManifest::fromArray($data);

        self::assertSame('0.2.0', $manifest->pulsar->minVersion);
        self::assertSame('1.0.0', $manifest->pulsar->maxVersion);
    }

    #[Test]
    public function fromArrayParsesProvidesConfig(): void
    {
        $data = [
            'name' => 'test',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'provides' => [
                'services' => ['ServiceA', 'ServiceB'],
                'commands' => ['CommandA'],
                'routes' => true,
                'middleware' => ['MiddlewareA'],
            ],
        ];

        $manifest = ExtensionManifest::fromArray($data);

        self::assertSame(['ServiceA', 'ServiceB'], $manifest->provides->services);
        self::assertSame(['CommandA'], $manifest->provides->commands);
        self::assertTrue($manifest->provides->routes);
        self::assertSame(['MiddlewareA'], $manifest->provides->middleware);
    }

    #[Test]
    public function fromArrayParsesRequiresConfig(): void
    {
        $data = [
            'name' => 'test',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'requires' => [
                'vendor/other' => '^1.0',
            ],
        ];

        $manifest = ExtensionManifest::fromArray($data);

        self::assertTrue($manifest->requires->requires('vendor/other'));
        self::assertSame('^1.0', $manifest->requires->getVersionConstraint('vendor/other'));
    }

    #[Test]
    public function shortNameReturnsNameWithoutVendor(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'vendor/test-extension',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
        ]);

        self::assertSame('test-extension', $manifest->shortName());
    }

    #[Test]
    public function vendorReturnsVendorPart(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'vendor/test-extension',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
        ]);

        self::assertSame('vendor', $manifest->vendor());
    }

    #[Test]
    public function hasDependenciesReturnsTrueWhenHasDeps(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'requires' => ['other' => '1.0'],
        ]);

        self::assertTrue($manifest->hasDependencies());
    }

    #[Test]
    public function hasDependenciesReturnsFalseWhenNoDeps(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
        ]);

        self::assertFalse($manifest->hasDependencies());
    }

    #[Test]
    public function getDependenciesReturnsExtensionNames(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'requires' => [
                'dep1' => '1.0',
                'dep2' => '2.0',
            ],
        ]);

        self::assertSame(['dep1', 'dep2'], $manifest->getDependencies());
    }

    #[Test]
    public function trustTierDefaultsToCommunityWhenMissing(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
        ]);

        self::assertSame(TrustTier::Community, $manifest->requestedTrustTier);
    }

    #[Test]
    public function trustTierParsesCoreTier(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'trust_tier' => 'core',
        ]);

        self::assertSame(TrustTier::Core, $manifest->requestedTrustTier);
    }

    #[Test]
    public function trustTierParsesVerifiedTier(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'trust_tier' => 'verified',
        ]);

        self::assertSame(TrustTier::Verified, $manifest->requestedTrustTier);
    }

    #[Test]
    public function trustTierParsesUntrustedTier(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'trust_tier' => 'untrusted',
        ]);

        self::assertSame(TrustTier::Untrusted, $manifest->requestedTrustTier);
    }

    #[Test]
    public function trustTierDefaultsToCommunityForInvalidValue(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'trust_tier' => 'invalid',
        ]);

        self::assertSame(TrustTier::Community, $manifest->requestedTrustTier);
    }

    #[Test]
    public function fromArrayPreservesMigrationsInProvides(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'vendor/cms',
            'version' => '1.0.0',
            'extension_class' => 'Vendor\\Cms\\CmsExtension',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'provides' => [
                'services' => ['ContentService'],
                'routes' => true,
                'migrations' => ['src/Migration', 'database/migrations'],
            ],
        ]);

        self::assertSame(['src/Migration', 'database/migrations'], $manifest->provides->migrations);
        self::assertTrue($manifest->provides->hasMigrations());
    }
}
