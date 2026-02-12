<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Config\TrustedExtensionsConfig;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;
use stdClass;

#[CoversClass(ExtensionBootstrap::class)]
final class TrustTierIntegrationTest extends TestCase
{
    private ExtensionBootstrap $bootstrap;
    private Container $container;
    private Router $router;

    protected function setUp(): void
    {
        $this->bootstrap = ExtensionBootstrap::create();
        $this->container = new Container();
        $this->router = new Router();

        // Register test services in the container
        $this->container->instance(LoggerInterface::class, new NullLogger());
        $this->container->instance('Pulsar\Security\Crypto\MasterKey', new stdClass());
        $this->container->instance('Pulsar\Database\ConnectionInterface', new stdClass());
    }

    #[Test]
    public function noPolicyMeansFullAccessForAllExtensions(): void
    {
        // No capability policy set — backward compatibility
        $extension = $this->createExtension('test/community');
        $manifest = $this->createManifest('test/community', 'community');

        $this->bootstrap->addExtension($extension, $manifest);
        $this->bootstrap->register($this->container);
        $this->bootstrap->boot($this->container, $this->router);

        // Extension gets full container access (no proxy)
        self::assertTrue($extension->booted);
    }

    #[Test]
    public function coreExtensionGetFullAccess(): void
    {
        $this->configurePolicy();

        $extension = $this->createResolvingExtension('pulsar/admin', 'Pulsar\Security\Crypto\MasterKey');
        $manifest = $this->createManifest('pulsar/admin', 'core');

        $trustedConfig = TrustedExtensionsConfig::fromArray([
            'pulsar/admin' => ['tier' => 'core'],
        ]);
        $this->bootstrap->trustedExtensionsConfig = $trustedConfig;

        $this->bootstrap->addExtension($extension, $manifest);
        $this->bootstrap->register($this->container);
        $this->bootstrap->boot($this->container, $this->router);

        // Core extension can resolve MasterKey
        self::assertTrue($extension->resolved);
    }

    #[Test]
    public function communityExtensionDeniedRestrictedServices(): void
    {
        $this->configurePolicy();

        $extension = $this->createResolvingExtension('acme/analytics', 'Pulsar\Security\Crypto\MasterKey');
        $manifest = $this->createManifest('acme/analytics', 'community');

        $this->bootstrap->addExtension($extension, $manifest);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('CryptoKeyAccess');

        $this->bootstrap->register($this->container);
    }

    #[Test]
    public function communityExtensionCanResolveSafeServices(): void
    {
        $this->configurePolicy();

        $extension = $this->createResolvingExtension('acme/logger', LoggerInterface::class);
        $manifest = $this->createManifest('acme/logger', 'community');

        $this->bootstrap->addExtension($extension, $manifest);
        $this->bootstrap->register($this->container);

        self::assertTrue($extension->resolved);
    }

    #[Test]
    public function effectiveTierResolvedFromHostPolicy(): void
    {
        $this->configurePolicy();

        // Extension requests verified, but host only allows community
        $extension = $this->createResolvingExtension('acme/ext', 'Pulsar\Database\ConnectionInterface');
        $manifest = $this->createManifest('acme/ext', 'verified');

        $trustedConfig = TrustedExtensionsConfig::fromArray([
            'acme/ext' => ['tier' => 'community'],
        ]);
        $this->bootstrap->trustedExtensionsConfig = $trustedConfig;

        $this->bootstrap->addExtension($extension, $manifest);

        // Community can't access database — effective tier is community, not verified
        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('DatabaseRaw');

        $this->bootstrap->register($this->container);
    }

    #[Test]
    public function mixedTierExtensionsBootCorrectly(): void
    {
        $this->configurePolicy();

        $trustedConfig = TrustedExtensionsConfig::fromArray([
            'pulsar/core-ext' => ['tier' => 'core'],
        ]);
        $this->bootstrap->trustedExtensionsConfig = $trustedConfig;

        // Core extension — full access
        $coreExt = $this->createResolvingExtension('pulsar/core-ext', LoggerInterface::class);
        $coreManifest = $this->createManifest('pulsar/core-ext', 'core');

        // Community extension — safe services only
        $communityExt = $this->createResolvingExtension('acme/community-ext', LoggerInterface::class);
        $communityManifest = $this->createManifest('acme/community-ext', 'community');

        $this->bootstrap->addExtension($coreExt, $coreManifest);
        $this->bootstrap->addExtension($communityExt, $communityManifest);

        $this->bootstrap->register($this->container);
        $this->bootstrap->boot($this->container, $this->router);

        self::assertTrue($coreExt->resolved);
        self::assertTrue($communityExt->resolved);
        self::assertTrue($coreExt->booted);
        self::assertTrue($communityExt->booted);
    }

    #[Test]
    public function communityExtensionRoutesPrefixed(): void
    {
        $this->configurePolicy();

        $extension = $this->createRoutingExtension('acme/widget', '/dashboard');
        $manifest = $this->createManifest('acme/widget', 'community');

        $this->bootstrap->addExtension($extension, $manifest);
        $this->bootstrap->register($this->container);
        $this->bootstrap->boot($this->container, $this->router);

        $routes = $this->router->routes();
        self::assertCount(1, $routes);
        self::assertSame('/ext/acme/widget/dashboard', $routes[0]->path);
    }

    #[Test]
    public function additionalCapabilitiesGrantAccess(): void
    {
        $this->configurePolicy();

        $extension = $this->createResolvingExtension('acme/db-ext', 'Pulsar\Database\ConnectionInterface');
        $manifest = $this->createManifest('acme/db-ext', 'community');

        $trustedConfig = TrustedExtensionsConfig::fromArray([
            'acme/db-ext' => [
                'tier' => 'community',
                'additional_capabilities' => ['DatabaseRaw'],
            ],
        ]);
        $this->bootstrap->trustedExtensionsConfig = $trustedConfig;

        $this->bootstrap->addExtension($extension, $manifest);
        $this->bootstrap->register($this->container);

        // Community with additional DatabaseRaw can resolve database services
        self::assertTrue($extension->resolved);
    }

    private function configurePolicy(): void
    {
        $this->bootstrap->capabilityPolicy = CapabilityPolicy::defaults();
        $this->bootstrap->serviceRestrictionMap = ServiceRestrictionMap::defaults();
    }

    private function createManifest(string $name, string $trustTier): ExtensionManifest
    {
        return ExtensionManifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'extension_class' => 'TestExtension',
            'trust_tier' => $trustTier,
        ]);
    }

    /** @return ExtensionInterface&object{booted: bool} */
    private function createExtension(string $name): ExtensionInterface
    {
        return new class ($name) implements ExtensionInterface {
            public bool $booted = false;

            public function __construct(private readonly string $extensionName) {}

            public function name(): string
            {
                return $this->extensionName;
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->booted = true;
            }

            public function providers(): array
            {
                return [];
            }
        };
    }

    /** @return ExtensionInterface&object{resolved: bool, booted: bool} */
    private function createResolvingExtension(string $name, string $serviceId): ExtensionInterface
    {
        return new class ($name, $serviceId) implements ExtensionInterface {
            public bool $resolved = false;
            public bool $booted = false;

            public function __construct(
                private readonly string $extensionName,
                private readonly string $serviceId,
            ) {}

            public function name(): string
            {
                return $this->extensionName;
            }

            public function register(ContainerInterface $container): void
            {
                $_ = $container->get($this->serviceId);
                $this->resolved = true;
            }

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->booted = true;
            }

            public function providers(): array
            {
                return [];
            }
        };
    }

    private function createRoutingExtension(string $name, string $routePath): ExtensionInterface
    {
        return new class ($name, $routePath) implements ExtensionInterface {
            public function __construct(
                private readonly string $extensionName,
                private readonly string $routePath,
            ) {}

            public function name(): string
            {
                return $this->extensionName;
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $router->get($this->routePath, fn() => 'ok');
            }

            public function providers(): array
            {
                return [];
            }
        };
    }
}
