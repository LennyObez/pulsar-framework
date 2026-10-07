<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

/**
 * Pins the one guarantee the trust-tier machinery actually makes.
 *
 * Nothing in Pulsar verifies an extension: no signature, no publisher key, no
 * trust store. `trust_tier` is read straight out of a pulsar.json that ships in
 * the same directory as the code it describes. That is only safe while the
 * field can lower an extension's privileges and never raise them — which is the
 * property asserted here, from both directions.
 *
 * It did not hold. {@see ExtensionBootstrap::resolveEffectiveTier()} returned
 * the manifest's requested tier verbatim whenever no {@see
 * TrustedExtensionsConfig} had been attached, so a manifest declaring
 * `"trust_tier": "core"` was handed Core — and Core bypasses both proxies. The
 * shipped boot path always attaches an allow-list, but `capabilityPolicy` is a
 * public property any embedder can set on its own, and a security boundary that
 * depends on a caller remembering a second assignment is not a boundary.
 */
#[CoversClass(ExtensionBootstrap::class)]
final class ManifestTierIsNotACredentialTest extends TestCase
{
    /**
     * The sandbox is on and no allow-list was attached. Nobody is listed, so
     * nobody is trusted: the manifest's `core` claim buys nothing and the
     * extension is denied a service that needs CryptoKeyAccess.
     */
    #[Test]
    public function manifestClaimingCoreIsSandboxedWhenNoAllowListIsAttached(): void
    {
        $bootstrap = $this->sandboxedBootstrap();

        $bootstrap->addExtension(
            $this->resolvingExtension('acme/evil', 'Pulsar\Security\Crypto\MasterKey'),
            $this->manifest('acme/evil', 'core'),
        );

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessageIsOrContains('CryptoKeyAccess');

        $bootstrap->register($this->container());
    }

    /**
     * The same claim buys nothing at the router either: Core would have received
     * the real router and could have registered anywhere in the application's
     * path space. Community gets its own prefix.
     */
    #[Test]
    public function manifestClaimingCoreGetsPrefixedRoutesWhenNoAllowListIsAttached(): void
    {
        $bootstrap = $this->sandboxedBootstrap();
        $router = new Router();

        $bootstrap->addExtension(
            $this->routingExtension('acme/evil', '/dashboard'),
            $this->manifest('acme/evil', 'core'),
        );

        $bootstrap->register($this->container());
        $bootstrap->boot($this->container(), $router);

        $routes = $router->routes();

        self::assertCount(1, $routes);
        self::assertSame('/ext/acme/evil/dashboard', $routes[0]->path);
    }

    /**
     * An absent allow-list must mean exactly what an empty one means. Stated
     * separately because "no config attached" is the path that used to be
     * treated as "no check", and the two cases silently diverging is how it
     * came to be one.
     */
    #[Test]
    public function anAbsentAllowListDeniesExactlyWhatAnEmptyOneDenies(): void
    {
        $bootstrap = $this->sandboxedBootstrap();
        $bootstrap->trustedExtensionsConfig = TrustedExtensionsConfig::fromArray([]);

        $bootstrap->addExtension(
            $this->resolvingExtension('acme/evil', 'Pulsar\Security\Crypto\MasterKey'),
            $this->manifest('acme/evil', 'core'),
        );

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessageIsOrContains('CryptoKeyAccess');

        $bootstrap->register($this->container());
    }

    /**
     * The other direction, which is the whole reason the field is worth parsing:
     * an extension the host trusts at Core may de-privilege ITSELF by declaring
     * a lower tier, and the framework honours it. Untrusted holds ContainerRead
     * and nothing else, so its route registration is refused even though the
     * host granted Core.
     */
    #[Test]
    public function manifestTierStillLowersWhatTheHostGranted(): void
    {
        $bootstrap = $this->sandboxedBootstrap();
        $bootstrap->trustedExtensionsConfig = TrustedExtensionsConfig::fromArray([
            'acme/humble' => ['tier' => 'core'],
        ]);

        $bootstrap->addExtension(
            $this->routingExtension('acme/humble', '/dashboard'),
            $this->manifest('acme/humble', 'untrusted'),
        );

        $bootstrap->register($this->container());

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessageIsOrContains('RouteRegister');

        $bootstrap->boot($this->container(), new Router());
    }

    /**
     * The cap has to survive an extension that does not ask politely.
     *
     * `Pulsar\Container\ContainerInterface` was classified SAFE in
     * `ServiceRestrictionMap::defaults()`, and the Kernel binds it to the real
     * container — so a sandboxed extension could resolve the container through
     * the proxy and then ask THAT for the master key, past every check. Without
     * this closed, the assertions above only describe an extension that chose to
     * be constrained.
     */
    #[Test]
    public function aSandboxedExtensionCannotResolveTheContainerAndWalkAroundTheProxy(): void
    {
        $bootstrap = $this->sandboxedBootstrap();

        $bootstrap->addExtension(
            $this->resolvingExtension('acme/evil', ContainerInterface::class),
            $this->manifest('acme/evil', 'core'),
        );

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessageIsOrContains('bypass every other check');

        $bootstrap->register($this->container());
    }

    /**
     * A bootstrap with the capability policy engaged and NO host allow-list —
     * the configuration that used to hand a manifest whatever it asked for.
     */
    private function sandboxedBootstrap(): ExtensionBootstrap
    {
        $bootstrap = ExtensionBootstrap::create();
        $bootstrap->capabilityPolicy = CapabilityPolicy::defaults();
        $bootstrap->serviceRestrictionMap = ServiceRestrictionMap::defaults();

        return $bootstrap;
    }

    /**
     * Bound the way {@see \Pulsar\Core\Kernel} binds them, self-reference
     * included — the self-reference is what made the escape above reachable.
     */
    private function container(): Container
    {
        $container = new Container();
        $container->instance('Pulsar\Security\Crypto\MasterKey', new stdClass());
        $container->instance(ContainerInterface::class, $container);

        return $container;
    }

    private function manifest(string $name, string $trustTier): ExtensionManifest
    {
        return ExtensionManifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'extension_class' => 'TestExtension',
            'trust_tier' => $trustTier,
        ]);
    }

    /**
     * An extension whose only act is to resolve one service, so the container
     * proxy's verdict is the whole outcome of register().
     */
    private function resolvingExtension(string $name, string $serviceId): ExtensionInterface
    {
        return new class ($name, $serviceId) implements ExtensionInterface {
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
            }

            public function boot(ContainerInterface $container, RouterInterface $router): void {}

            public function providers(): array
            {
                return [];
            }
        };
    }

    private function routingExtension(string $name, string $routePath): ExtensionInterface
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
                $router->get($this->routePath, static fn(): string => 'ok');
            }

            public function providers(): array
            {
                return [];
            }
        };
    }
}
