<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Boot\ExtensionSandbox;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;
use stdClass;

use function dirname;

use const DIRECTORY_SEPARATOR;

/**
 * Proves the sandbox is not inert once {@see ExtensionSandbox::harden} runs with
 * the framework's real shipped config/extensions.php:
 *   - a bundled (listed, core) extension keeps full container access;
 *   - an unlisted extension is capped at Community even though its manifest
 *     requests core, and is denied a crypto-key service.
 */
#[CoversClass(ExtensionSandbox::class)]
#[CoversClass(ExtensionBootstrap::class)]
final class ExtensionSandboxEngagementTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        // The framework's own config/ directory (repo root / config).
        $this->configDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config';
    }

    #[Test]
    public function unlistedExtensionIsSandboxedDespiteRequestingCore(): void
    {
        $bootstrap = ExtensionBootstrap::create();
        ExtensionSandbox::harden($bootstrap, $this->configDir);

        // Not in config/extensions.php: capped at Community regardless of the
        // core tier its manifest brazenly requests. MasterKey requires
        // CryptoKeyAccess, which Community lacks — resolution is denied.
        $extension = $this->resolvingExtension('acme/evil', 'Pulsar\Security\Crypto\MasterKey');
        $bootstrap->addExtension($extension, $this->manifest('acme/evil', 'core'));

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessageIsOrContains('CryptoKeyAccess');

        $bootstrap->register($this->newContainer());
    }

    #[Test]
    public function bundledExtensionListedAtCoreKeepsFullAccess(): void
    {
        $bootstrap = ExtensionBootstrap::create();
        ExtensionSandbox::harden($bootstrap, $this->configDir);

        // pulsar/orm ships listed at core: Core bypasses the proxy entirely, so
        // it resolves the restricted MasterKey service unhindered.
        $extension = $this->resolvingExtension('pulsar/orm', 'Pulsar\Security\Crypto\MasterKey');
        $bootstrap->addExtension($extension, $this->manifest('pulsar/orm', 'core'));

        $bootstrap->register($this->newContainer());
        $bootstrap->boot($this->newContainer(), new Router());

        self::assertTrue($extension->resolved);
    }

    private function newContainer(): Container
    {
        $container = new Container();
        $container->instance('Pulsar\Security\Crypto\MasterKey', new stdClass());

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

    /** @return ExtensionInterface&object{resolved: bool} */
    private function resolvingExtension(string $name, string $serviceId): ExtensionInterface
    {
        return new class ($name, $serviceId) implements ExtensionInterface {
            public bool $resolved = false;

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

            public function boot(ContainerInterface $container, RouterInterface $router): void {}

            public function providers(): array
            {
                return [];
            }
        };
    }
}
