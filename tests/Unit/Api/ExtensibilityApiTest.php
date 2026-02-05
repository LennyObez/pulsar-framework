<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Extensibility\Exception\DependencyException;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\Exception\ManifestException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionLifecycle;
use Pulsar\Extensibility\ExtensionLoader;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Extensibility\Manifest\ProvidesConfig;
use Pulsar\Extensibility\Manifest\PulsarVersionConfig;
use Pulsar\Extensibility\Manifest\RequiresConfig;
use Pulsar\Extensibility\ServiceProviderInterface;

#[CoversClass(Api::class)]
final class ExtensibilityApiTest extends TestCase
{
    use ApiAssertionsTrait;

    #[Test]
    public function extensionInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(ExtensionInterface::class);
    }

    #[Test]
    public function extensionInterfaceHasRequiredMethods(): void
    {
        self::assertMethodSignature(ExtensionInterface::class, 'name', [], 'string');
    }

    #[Test]
    public function serviceProviderInterfaceIsPublicApi(): void
    {
        self::assertHasApiAttribute(ServiceProviderInterface::class);
    }

    #[Test]
    public function extensionLifecycleIsPublicApi(): void
    {
        self::assertHasApiAttribute(ExtensionLifecycle::class);
    }

    #[Test]
    public function extensionManifestIsPublicApi(): void
    {
        self::assertHasApiAttribute(ExtensionManifest::class);
        self::assertClassIsReadonly(ExtensionManifest::class);
    }

    #[Test]
    public function manifestDtosArePublicApi(): void
    {
        self::assertHasApiAttribute(ProvidesConfig::class);
        self::assertHasApiAttribute(PulsarVersionConfig::class);
        self::assertHasApiAttribute(RequiresConfig::class);
        self::assertClassIsReadonly(ProvidesConfig::class);
        self::assertClassIsReadonly(PulsarVersionConfig::class);
        self::assertClassIsReadonly(RequiresConfig::class);
    }

    #[Test]
    public function extensionExceptionsArePublicApi(): void
    {
        self::assertHasApiAttribute(DependencyException::class);
        self::assertHasApiAttribute(ExtensionException::class);
        self::assertHasApiAttribute(ManifestException::class);
    }

    #[Test]
    public function extensionRegistryIsInternal(): void
    {
        self::assertHasInternalAttribute(ExtensionRegistry::class);
    }

    #[Test]
    public function extensionBootstrapIsInternal(): void
    {
        self::assertHasInternalAttribute(ExtensionBootstrap::class);
    }

    #[Test]
    public function extensionLoaderIsInternal(): void
    {
        self::assertHasInternalAttribute(ExtensionLoader::class);
    }
}
