<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Wiring\IntegrityWiring;
use Pulsar\Deploy\Check\IntegrityCheck;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Integrity\IntegrityManifest;
use Pulsar\Integrity\IntegrityPolicy;
use Pulsar\Integrity\ManifestBuilder;
use Pulsar\Integrity\ManifestBuilderInterface;
use Pulsar\Integrity\ManifestFormat;
use Pulsar\Integrity\ManifestSignerInterface;
use Pulsar\Integrity\ManifestVerifier;
use Pulsar\Integrity\ManifestVerifierInterface;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;
use Throwable;

#[CoversClass(IntegrityWiring::class)]
final class IntegrityWiringTest extends TestCase
{
    #[Test]
    public function wireRegistersIntegrityServicesWhenEnabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true);
        $configManager->load();

        $wiring = new IntegrityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(IntegrityConfig::class));
        self::assertTrue($container->has(IntegrityPolicy::class));
        self::assertTrue($container->has(ManifestBuilder::class));
        self::assertTrue($container->has(ManifestBuilderInterface::class));
        self::assertTrue($container->has(ManifestVerifier::class));
        self::assertTrue($container->has(ManifestVerifierInterface::class));

        // The deploy check is composed here because this is where the verifier,
        // the signer and the base path exist; DeployWiring resolves it.
        self::assertTrue($container->has(IntegrityCheck::class));
    }

    #[Test]
    public function wiredIntegrityCheckVerifiesTheManifestRatherThanTheFlag(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: true);
        $configManager->load();

        $wiring = new IntegrityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        /** @var IntegrityCheck $check */
        $check = $container->get(IntegrityCheck::class);

        // Integrity is enabled and no manifest was ever built, so the gate must
        // fail rather than report a pass on the configuration flag.
        $result = $check->check('production');

        self::assertSame(CheckSeverity::Error, $result->severity);
        self::assertStringContainsString('manifest', $result->message);
    }

    /**
     * The manifest binding exists to serve consumers outside the deploy gate —
     * the health dashboard resolves it, and gets null when there is none.
     *
     * No signing key means no way to tell the shipped manifest from one an
     * intruder wrote, so nothing is bound and the dashboard reports a failure
     * rather than trusting an unauthenticated file.
     */
    #[Test]
    public function noManifestIsBoundWithoutASigningKey(): void
    {
        $container = new Container();
        $configManager = $this->createConfigManager(enabled: true);
        $configManager->load();

        new IntegrityWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        self::assertFalse($container->has(IntegrityManifest::class));
    }

    #[Test]
    public function theBoundManifestIsTheSignedOneFromDisk(): void
    {
        [$container, $configManager, $projectRoot] = $this->wireWithSigner();

        new IntegrityWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        $this->writeSignedManifest($container, $projectRoot, tamper: false);

        self::assertTrue($container->has(IntegrityManifest::class));

        /** @var IntegrityManifest $manifest */
        $manifest = $container->get(IntegrityManifest::class);

        self::assertNotNull($manifest->scope);
        self::assertSame(['src/**/*.php'], $manifest->scope->include);

        $this->removeDirectory($projectRoot);
    }

    #[Test]
    public function aManifestWhoseSignatureDoesNotHoldIsRefused(): void
    {
        [$container, $configManager, $projectRoot] = $this->wireWithSigner();

        new IntegrityWiring()->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            new MiddlewareRegistry(),
            new Router(),
        );

        $this->writeSignedManifest($container, $projectRoot, tamper: true);

        try {
            $_ = $container->get(IntegrityManifest::class);
            self::fail('a manifest with a broken signature resolved');
        } catch (Throwable $e) {
            self::assertStringContainsString('signature', $e->getMessage());
        }

        $this->removeDirectory($projectRoot);
    }

    /**
     * @return array{Container, ConfigManager, string}
     */
    private function wireWithSigner(): array
    {
        $projectRoot = sys_get_temp_dir() . '/pulsar_integrity_project_' . bin2hex(random_bytes(4));
        @mkdir($projectRoot . '/config', 0o755, true);
        @mkdir($projectRoot . '/src/Core', 0o755, true);

        file_put_contents($projectRoot . '/src/Core/Kernel.php', '<?php class Kernel {}');
        $this->writeBaseConfig($projectRoot . '/config', enabled: true);

        $container = new Container();
        $container->instance(HmacInterface::class, new HmacService());
        $container->instance(MasterKey::class, MasterKey::fromHex(sodium_bin2hex(random_bytes(32))));

        $configManager = new ConfigManager($projectRoot . '/config');
        $configManager->load();

        return [$container, $configManager, $projectRoot];
    }

    private function writeSignedManifest(Container $container, string $projectRoot, bool $tamper): void
    {
        /** @var ManifestBuilderInterface $builder */
        $builder = $container->get(ManifestBuilderInterface::class);
        /** @var ManifestSignerInterface $signer */
        $signer = $container->get(ManifestSignerInterface::class);

        $manifest = $builder->build(['src/**/*.php'], []);
        $signature = $signer->sign($manifest);

        if ($tamper) {
            $signature = strrev($signature);
        }

        @mkdir($projectRoot . '/var/integrity', 0o755, true);
        file_put_contents(
            $projectRoot . '/var/integrity/manifest.json',
            ManifestFormat::toJson($manifest, $signature),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    #[Test]
    public function wireSkipsWhenDisabled(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManager(enabled: false);
        $configManager->load();

        $wiring = new IntegrityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertTrue($container->has(IntegrityConfig::class));
        self::assertFalse($container->has(IntegrityPolicy::class));

        // Still registered when disabled: the check has to be able to fail a
        // production deploy that turned integrity off.
        self::assertTrue($container->has(IntegrityCheck::class));
    }

    #[Test]
    public function wireSkipsWhenNoConfig(): void
    {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $configManager = $this->createConfigManagerWithout();
        $configManager->load();

        $wiring = new IntegrityWiring();
        $wiring->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        self::assertFalse($container->has(IntegrityConfig::class));
    }

    private function createConfigManager(bool $enabled): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_integrity_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        $this->writeBaseConfig($configPath, $enabled);

        return new ConfigManager($configPath);
    }

    private function writeBaseConfig(string $configPath, bool $enabled): void
    {
        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');
        file_put_contents(
            $configPath . '/integrity.php',
            '<?php return ["enabled" => ' . ($enabled ? 'true' : 'false') . '];',
        );
    }

    private function createConfigManagerWithout(): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_integrity_wiring_no_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];');

        return new ConfigManager($configPath);
    }
}
