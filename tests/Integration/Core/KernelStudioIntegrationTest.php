<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Extension\Studio\Console\Collector\ExceptionCollector;
use Pulsar\Extension\Studio\Console\Collector\HttpCollector;
use Pulsar\Extension\Studio\Console\Collector\LogCollector;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceExporter;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceVerifier;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\FiberScopedContextProvider;
use Pulsar\Extension\Studio\Security\StudioAccessGate;
use Pulsar\Extension\Studio\StudioExtension;
use Pulsar\Extension\Studio\StudioManager;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Pulsar\Observability\Log\Sink\DeferredSink;

#[CoversClass(Kernel::class)]
#[CoversClass(StudioExtension::class)]
final class KernelStudioIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_studio_test_' . uniqid();
        mkdir($this->tempDir, 0o775, true);

        // Clear env vars that could interfere with Studio detection
        putenv('APP_NAME');
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('LOG_LEVEL');
        putenv('LOG_CHANNEL');
        putenv('STUDIO_ENABLED');
        putenv('STUDIO_DISABLED');
        putenv('STUDIO_PRODUCTION_CONFIRM');
        putenv('STUDIO_STORAGE_PATH');
        putenv('STUDIO_SAMPLING_RATE');
    }

    protected function tearDown(): void
    {
        putenv('APP_NAME');
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('LOG_LEVEL');
        putenv('LOG_CHANNEL');
        putenv('STUDIO_ENABLED');
        putenv('STUDIO_DISABLED');
        putenv('STUDIO_PRODUCTION_CONFIRM');
        putenv('STUDIO_STORAGE_PATH');
        putenv('STUDIO_SAMPLING_RATE');

        $this->cleanDir($this->tempDir);
    }

    #[Test]
    public function testKernelBootsWithStudioConfigAndRegistersServices(): void
    {
        $this->writeBaseConfigFiles();
        $this->writeStudioConfig(enabled: true);

        $kernel = $this->createKernelWithStudio();
        $kernel->boot();
        $container = $kernel->container();

        self::assertTrue($container->has(StudioManager::class));
        self::assertTrue($container->has(EventStoreInterface::class));
        self::assertTrue($container->has(FiberScopedContextProvider::class));
        self::assertTrue($container->has(CorrelationContextProviderInterface::class));
        self::assertTrue($container->has(DashboardAggregator::class));
        self::assertTrue($container->has(TimelineBuilder::class));
        self::assertTrue($container->has(StudioAccessGate::class));
        self::assertTrue($container->has(EvidenceVerifier::class));
        self::assertTrue($container->has(EvidenceExporter::class));
        self::assertTrue($container->has(StudioConfig::class));
    }

    #[Test]
    public function testKernelBootsWithoutStudioConfigFileAndHasNoStudioServices(): void
    {
        $this->writeBaseConfigFiles();
        // No studio.php written

        $kernel = $this->createKernelWithStudio();
        $kernel->boot();
        $container = $kernel->container();

        self::assertFalse($container->has(StudioManager::class));
        self::assertFalse($container->has(EventStoreInterface::class));
    }

    #[Test]
    public function testKernelBootsWithStudioDisabledInConfigAndHasNoStudioManager(): void
    {
        $this->writeBaseConfigFiles();
        $this->writeStudioConfig(enabled: false);

        $kernel = $this->createKernelWithStudio();
        $kernel->boot();
        $container = $kernel->container();

        // StudioConfig IS loaded (file exists, so preBoot loads and registers it)
        self::assertTrue($container->has(StudioConfig::class));

        // StudioManager is NOT registered (preBoot returned early because enabled=false)
        self::assertFalse($container->has(StudioManager::class));
    }

    #[Test]
    public function testDeferredSinkRegisteredWhenStudioConfigExists(): void
    {
        $this->writeBaseConfigFiles();
        $this->writeStudioConfig(enabled: true);

        $kernel = $this->createKernelWithStudio();
        $kernel->boot();
        $container = $kernel->container();

        self::assertTrue($container->has(DeferredSink::class));
    }

    #[Test]
    public function testDeferredSinkAlwaysPresentEvenWithoutStudioConfig(): void
    {
        $this->writeBaseConfigFiles();
        // No studio.php written

        $kernel = $this->createKernelWithStudio();
        $kernel->boot();
        $container = $kernel->container();

        // DeferredSink is always-present as a generic extension point
        self::assertTrue($container->has(DeferredSink::class));
    }

    #[Test]
    public function testCollectorsAttachedWhenStudioEnabled(): void
    {
        $this->writeBaseConfigFiles();
        $this->writeStudioConfig(enabled: true);
        $this->writeObservabilityConfigWithErrorTracking();

        $kernel = $this->createKernelWithStudio();
        $kernel->boot();
        $container = $kernel->container();

        self::assertTrue($container->has(HttpCollector::class));
        self::assertTrue($container->has(LogCollector::class));
        self::assertTrue($container->has(ExceptionCollector::class));
    }

    #[Test]
    public function testNoCollectorsWhenStudioDisabled(): void
    {
        $this->writeBaseConfigFiles();
        $this->writeStudioConfig(enabled: false);

        $kernel = $this->createKernelWithStudio();
        $kernel->boot();
        $container = $kernel->container();

        self::assertFalse($container->has(HttpCollector::class));
    }

    private function createKernelWithStudio(): Kernel
    {
        $configManager = new ConfigManager(configPath: $this->tempDir);

        $bootstrap = ExtensionBootstrap::create();
        $bootstrap->addExtension(
            new StudioExtension(),
            ExtensionManifest::fromArray([
                'name' => 'pulsar/studio',
                'version' => '1.0.0-rc.1',
                'extension_class' => StudioExtension::class,
            ]),
        );

        return new Kernel(
            extensionBootstrap: $bootstrap,
            configManager: $configManager,
        );
    }

    private function writeBaseConfigFiles(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'app.php', <<<'PHP'
            <?php return [
                'name' => 'Test',
                'env' => 'local',
                'debug' => true,
                'timezone' => 'UTC',
                'locale' => 'en',
            ];
            PHP);

        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'observability.php', <<<'PHP'
            <?php return [
                'logging' => ['level' => 'debug', 'channels' => []],
                'tracing' => ['enabled' => false],
                'metrics' => ['enabled' => false],
                'error_tracking' => ['enabled' => false],
                'audit' => ['enabled' => false],
            ];
            PHP);

        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'security.php', <<<'PHP'
            <?php return [
                'session' => [],
                'csrf' => [],
                'headers' => [],
            ];
            PHP);
    }

    /**
     * Write an observability config with error tracking enabled.
     *
     * Overwrites the default observability.php from writeBaseConfigFiles().
     */
    private function writeObservabilityConfigWithErrorTracking(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'observability.php', <<<'PHP'
            <?php return [
                'logging' => ['level' => 'debug', 'channels' => []],
                'tracing' => ['enabled' => false],
                'metrics' => ['enabled' => false],
                'error_tracking' => [
                    'enabled' => true,
                    'sensitive_fields' => [],
                    'max_groups' => 100,
                    'max_recent_events_per_group' => 10,
                ],
                'audit' => ['enabled' => false],
            ];
            PHP);
    }

    private function writeStudioConfig(bool $enabled): void
    {
        $enabledStr = $enabled ? 'true' : 'false';
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'studio.php', <<<PHP
            <?php return [
                'enabled' => {$enabledStr},
                'storage_path' => ':memory:',
            ];
            PHP);
    }

    private function cleanDir(string $dir): void
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

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->cleanDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
