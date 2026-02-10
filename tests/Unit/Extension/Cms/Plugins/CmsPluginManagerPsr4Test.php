<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Plugins;

use DateTimeImmutable;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Internal\Plugins\CmsPluginManager;
use Pulsar\Extension\Cms\Internal\Plugins\HookExecutionEngine;
use Pulsar\Extension\Cms\Plugins\CmsPluginInterface;
use Pulsar\Extension\Cms\Plugins\CmsPluginRepositoryInterface;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use Pulsar\Extension\Cms\Plugins\InstalledCmsPlugin;
use Pulsar\Extension\Cms\Plugins\PluginManifest;
use Pulsar\Extension\Cms\Plugins\PluginManifestValidatorInterface;
use Pulsar\Extension\Cms\Plugins\PluginProvenanceVerifierInterface;
use Pulsar\Extension\Cms\Themes\ThemeArchiveExtractorInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;

use function count;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function rmdir;
use function spl_autoload_unregister;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

#[CoversClass(CmsPluginManager::class)]
final class CmsPluginManagerPsr4Test extends TestCase
{
    private string $tempDir;
    private CmsPluginManager $manager;
    private LoggerInterface&Stub $logger;

    /** @var list<callable> */
    private array $registeredAutoloaders = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_psr4_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);

        $this->logger = $this->createStub(LoggerInterface::class);
        $hookRegistry = new HookRegistry();

        $this->manager = new CmsPluginManager(
            repository: $this->createStub(CmsPluginRepositoryInterface::class),
            manifestValidator: $this->createStub(PluginManifestValidatorInterface::class),
            provenanceVerifier: $this->createStub(PluginProvenanceVerifierInterface::class),
            archiveExtractor: $this->createStub(ThemeArchiveExtractorInterface::class),
            config: CmsSecurityConfig::fromArray([]),
            container: $this->createStub(ContainerInterface::class),
            hookRegistry: $hookRegistry,
            hookEngine: new HookExecutionEngine($hookRegistry, null, $this->logger),
            eventDispatcher: $this->createStub(EventDispatcherInterface::class),
            auditLogger: $this->createStub(AuditLoggerInterface::class),
            logger: $this->logger,
        );
    }

    protected function tearDown(): void
    {
        // Unregister any autoloaders we registered during tests
        foreach ($this->registeredAutoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        $this->registeredAutoloaders = [];
        $this->removeDirectory($this->tempDir);
    }

    // -- PluginManifest autoload field ----------------------------------------

    #[Test]
    public function pluginManifestParsesAutoloadField(): void
    {
        $manifest = PluginManifest::fromArray([
            'slug' => 'test-plugin',
            'display_name' => 'Test Plugin',
            'version' => '1.0.0',
            'entry_point' => 'Vendor\\TestPlugin\\Plugin',
            'autoload' => [
                'psr-4' => [
                    'Vendor\\TestPlugin\\' => 'src/',
                ],
            ],
        ]);

        self::assertNotNull($manifest->autoload);
        self::assertArrayHasKey('psr-4', $manifest->autoload);
        self::assertSame('src/', $manifest->autoload['psr-4']['Vendor\\TestPlugin\\']);
    }

    #[Test]
    public function pluginManifestAutoloadDefaultsToNull(): void
    {
        $manifest = PluginManifest::fromArray([
            'slug' => 'test-plugin',
            'display_name' => 'Test Plugin',
            'version' => '1.0.0',
        ]);

        self::assertNull($manifest->autoload);
    }

    // -- PSR-4 autoloader registration ----------------------------------------

    #[Test]
    public function registerPsr4AutoloaderResolvesClassFromNamespace(): void
    {
        // Create a plugin directory with PSR-4 structure
        $pluginDir = $this->tempDir . '/my-plugin';
        $srcDir = $pluginDir . '/src';
        mkdir($srcDir, 0o755, true);

        // Write a dummy class file
        $className = 'PulsarTestPsr4_' . bin2hex(random_bytes(4));
        $namespace = $className . 'Ns';
        $fqcn = $namespace . '\\MyClass';

        file_put_contents($srcDir . '/MyClass.php', <<<PHP
            <?php
            namespace {$namespace};
            class MyClass {}
            PHP);

        // Use reflection to call the private method
        $method = new ReflectionMethod(CmsPluginManager::class, 'registerPsr4Autoloader');

        $method->invoke($this->manager, $pluginDir, [$namespace . '\\' => 'src/']);

        // Track the autoloader for cleanup — it's the last registered one
        $autoloaders = spl_autoload_functions();
        $this->registeredAutoloaders[] = $autoloaders[count($autoloaders) - 1];

        self::assertTrue(class_exists($fqcn, true));
    }

    #[Test]
    public function registerPsr4AutoloaderIgnoresNonMatchingPrefix(): void
    {
        $pluginDir = $this->tempDir . '/other-plugin';
        mkdir($pluginDir . '/src', 0o755, true);

        $method = new ReflectionMethod(CmsPluginManager::class, 'registerPsr4Autoloader');

        $method->invoke($this->manager, $pluginDir, ['Vendor\\Other\\' => 'src/']);

        $autoloaders = spl_autoload_functions();
        $this->registeredAutoloaders[] = $autoloaders[count($autoloaders) - 1];

        // A class from a completely different namespace should not be found
        $uniqueClass = 'Unrelated\\Namespace\\Class_' . bin2hex(random_bytes(4));
        self::assertFalse(class_exists($uniqueClass, true));
    }

    #[Test]
    public function registerPsr4AutoloaderHandlesNestedNamespaces(): void
    {
        $pluginDir = $this->tempDir . '/nested-plugin';
        $subDir = $pluginDir . '/src/Sub/Deep';
        mkdir($subDir, 0o755, true);

        $className = 'PulsarTestNested_' . bin2hex(random_bytes(4));
        $namespace = $className . 'Ns';
        $fqcn = $namespace . '\\Sub\\Deep\\Widget';

        file_put_contents($subDir . '/Widget.php', <<<PHP
            <?php
            namespace {$namespace}\\Sub\\Deep;
            class Widget {}
            PHP);

        $method = new ReflectionMethod(CmsPluginManager::class, 'registerPsr4Autoloader');

        $method->invoke($this->manager, $pluginDir, [$namespace . '\\' => 'src/']);

        $autoloaders = spl_autoload_functions();
        $this->registeredAutoloaders[] = $autoloaders[count($autoloaders) - 1];

        self::assertTrue(class_exists($fqcn, true));
    }

    // -- loadPluginInstance priority -----------------------------------------

    #[Test]
    public function loadPluginInstanceReturnsNullWhenManifestMissing(): void
    {
        $plugin = $this->createInstalledPlugin($this->tempDir . '/nonexistent');

        $method = new ReflectionMethod(CmsPluginManager::class, 'loadPluginInstance');
        $result = $method->invoke($this->manager, $plugin);

        self::assertNull($result);
    }

    #[Test]
    public function loadPluginInstanceReturnsNullWhenEntryPointMissing(): void
    {
        $pluginDir = $this->tempDir . '/no-entry';
        mkdir($pluginDir, 0o755, true);

        file_put_contents($pluginDir . '/plugin.json', json_encode([
            'slug' => 'no-entry',
            'display_name' => 'No Entry Plugin',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));

        $plugin = $this->createInstalledPlugin($pluginDir);

        $method = new ReflectionMethod(CmsPluginManager::class, 'loadPluginInstance');
        $result = $method->invoke($this->manager, $plugin);

        self::assertNull($result);
    }

    #[Test]
    public function loadPluginInstanceUsesPsr4AutoloadFromManifest(): void
    {
        $pluginDir = $this->tempDir . '/psr4-plugin';
        $srcDir = $pluginDir . '/src';
        mkdir($srcDir, 0o755, true);

        $uniqueSuffix = bin2hex(random_bytes(4));
        $namespace = 'PulsarPsr4Plugin_' . $uniqueSuffix;
        $fqcn = $namespace . '\\Plugin';

        // Write a real CmsPluginInterface implementation
        file_put_contents($srcDir . '/Plugin.php', <<<PHP
            <?php
            namespace {$namespace};
            use Pulsar\Extension\Cms\Plugins\CmsPluginInterface;
            use Pulsar\Extension\Cms\Plugins\CmsPluginContext;
            use Pulsar\Extension\Cms\Plugins\PluginCapability;
            class Plugin implements CmsPluginInterface {
                public function name(): string { return 'PSR-4 Test Plugin'; }
                public function register(CmsPluginContext \$context): void {}
                public function boot(CmsPluginContext \$context): void {}
                public function capabilities(): array { return []; }
            }
            PHP);

        file_put_contents($pluginDir . '/plugin.json', json_encode([
            'slug' => 'psr4-plugin',
            'display_name' => 'PSR-4 Plugin',
            'version' => '1.0.0',
            'entry_point' => $fqcn,
            'autoload' => [
                'psr-4' => [
                    $namespace . '\\' => 'src/',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $plugin = $this->createInstalledPlugin($pluginDir);

        $method = new ReflectionMethod(CmsPluginManager::class, 'loadPluginInstance');
        $result = $method->invoke($this->manager, $plugin);

        // Clean up the registered autoloader
        $autoloaders = spl_autoload_functions();
        $this->registeredAutoloaders[] = $autoloaders[count($autoloaders) - 1];

        self::assertInstanceOf(CmsPluginInterface::class, $result);
        self::assertSame('PSR-4 Test Plugin', $result->name());
    }

    #[Test]
    public function loadPluginInstanceFallsBackToDirectFileRequire(): void
    {
        $pluginDir = $this->tempDir . '/fallback-plugin';
        $srcDir = $pluginDir . '/src';
        mkdir($srcDir, 0o755, true);

        $uniqueSuffix = bin2hex(random_bytes(4));
        $fqcn = 'FallbackPlugin_' . $uniqueSuffix;

        // Place file at src/{ClassName}.php (fallback path)
        file_put_contents($srcDir . '/' . $fqcn . '.php', <<<PHP
            <?php
            use Pulsar\Extension\Cms\Plugins\CmsPluginInterface;
            use Pulsar\Extension\Cms\Plugins\CmsPluginContext;
            class {$fqcn} implements CmsPluginInterface {
                public function name(): string { return 'Fallback Plugin'; }
                public function register(CmsPluginContext \$context): void {}
                public function boot(CmsPluginContext \$context): void {}
                public function capabilities(): array { return []; }
            }
            PHP);

        // No autoload in manifest — should use fallback
        file_put_contents($pluginDir . '/plugin.json', json_encode([
            'slug' => 'fallback-plugin',
            'display_name' => 'Fallback Plugin',
            'version' => '1.0.0',
            'entry_point' => $fqcn,
        ], JSON_THROW_ON_ERROR));

        $plugin = $this->createInstalledPlugin($pluginDir);

        $method = new ReflectionMethod(CmsPluginManager::class, 'loadPluginInstance');
        $result = $method->invoke($this->manager, $plugin);

        self::assertInstanceOf(CmsPluginInterface::class, $result);
        self::assertSame('Fallback Plugin', $result->name());
    }

    #[Test]
    public function loadPluginInstanceReturnsNullForUnresolvableClass(): void
    {
        $pluginDir = $this->tempDir . '/unresolvable-plugin';
        mkdir($pluginDir, 0o755, true);

        file_put_contents($pluginDir . '/plugin.json', json_encode([
            'slug' => 'unresolvable',
            'display_name' => 'Unresolvable Plugin',
            'version' => '1.0.0',
            'entry_point' => 'Nonexistent\\Class\\That\\DoesNotExist_' . bin2hex(random_bytes(4)),
        ], JSON_THROW_ON_ERROR));

        $plugin = $this->createInstalledPlugin($pluginDir);

        $method = new ReflectionMethod(CmsPluginManager::class, 'loadPluginInstance');
        $result = $method->invoke($this->manager, $plugin);

        self::assertNull($result);
    }

    // -- Helpers ---------------------------------------------------------------

    private function createInstalledPlugin(string $storagePath): InstalledCmsPlugin
    {
        return new InstalledCmsPlugin(
            id: 'test-id-' . bin2hex(random_bytes(4)),
            tenantId: null,
            slug: 'test-plugin',
            displayName: 'Test Plugin',
            version: '1.0.0',
            description: null,
            authorName: null,
            authorUrl: null,
            license: null,
            manifestHash: 'abc123',
            packageHash: 'def456',
            provenanceVerified: true,
            signatureVerified: true,
            capabilities: [],
            bootOrder: 0,
            isEnabled: true,
            storagePath: $storagePath,
            installedAt: new DateTimeImmutable(),
            installedBy: 'test-user',
            enabledAt: null,
            enabledBy: null,
            disabledAt: null,
            deletedAt: null,
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
