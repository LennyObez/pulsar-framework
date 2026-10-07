<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Core\Kernel;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\Http\Method;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Observability\Log\Logger;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Security\Session\SessionManager;

#[CoversClass(Kernel::class)]
final class KernelBootPipelineTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_kernel_test_' . uniqid();
        mkdir($this->tempDir, 0o777, true);

        // Write minimal app config (always required)
        file_put_contents($this->tempDir . '/app.php', "<?php\nreturn ['debug' => false];");

        // Write minimal security config (always required)
        file_put_contents($this->tempDir . '/security.php', "<?php\nreturn [];");
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function bootWithConfigCreatesLoggerAndExceptionHandler(): void
    {
        $this->writeObservabilityConfig();
        $kernel = $this->bootKernel();

        self::assertTrue($kernel->container()->has(LoggerInterface::class));
        self::assertTrue($kernel->container()->has(Logger::class));
        self::assertTrue($kernel->container()->has(ExceptionHandler::class));
    }

    #[Test]
    public function bootWithMetricsEnabledRegistersMetricRegistry(): void
    {
        $this->writeObservabilityConfig(metricsEnabled: true);
        $kernel = $this->bootKernel();

        self::assertTrue($kernel->container()->has(MetricRegistry::class));
    }

    #[Test]
    public function bootWithMetricsDisabledDoesNotRegisterMetricRegistry(): void
    {
        $this->writeObservabilityConfig(metricsEnabled: false);
        $kernel = $this->bootKernel();

        self::assertFalse($kernel->container()->has(MetricRegistry::class));
    }

    #[Test]
    public function bootWithExporterEnabledRegistersMetricsEndpoint(): void
    {
        $this->writeObservabilityConfig(metricsEnabled: true, exporterEnabled: true, exporterEndpoint: '/test-metrics');
        $kernel = $this->bootKernel();

        // Router::match() throws RoutingException if no route matches,
        // so reaching this assertion means the endpoint was registered.
        $matched = $kernel->router()->match(Method::GET, '/test-metrics');
        self::assertInstanceOf(MatchedRoute::class, $matched);
    }

    #[Test]
    public function bootWithTracingEnabledRegistersSpanCollector(): void
    {
        $this->writeObservabilityConfig(tracingEnabled: true);
        $kernel = $this->bootKernel();

        self::assertTrue($kernel->container()->has(InMemorySpanCollector::class));
    }

    #[Test]
    public function bootWithTracingDisabledDoesNotRegisterSpanCollector(): void
    {
        $this->writeObservabilityConfig(tracingEnabled: false);
        $kernel = $this->bootKernel();

        self::assertFalse($kernel->container()->has(InMemorySpanCollector::class));
    }

    #[Test]
    public function bootRegistersCoreSecurityServices(): void
    {
        $this->writeObservabilityConfig();
        $kernel = $this->bootKernel();

        self::assertTrue($kernel->container()->has(SessionManager::class));
    }

    #[Test]
    public function bootWithDebugModeRegistersExceptionHandler(): void
    {
        file_put_contents($this->tempDir . '/app.php', "<?php\nreturn ['debug' => true];");
        $this->writeObservabilityConfig();
        $kernel = $this->bootKernel();

        self::assertTrue($kernel->container()->has(ExceptionHandler::class));
    }

    #[Test]
    public function bootWithErrorTrackingDisabledSkipsAggregatorRegistration(): void
    {
        $this->writeObservabilityConfig(errorTrackingEnabled: false);
        $kernel = $this->bootKernel();

        self::assertFalse($kernel->container()->has(ErrorAggregator::class));
    }

    #[Test]
    public function bootWithErrorTrackingEnabledRegistersAggregator(): void
    {
        $this->writeObservabilityConfig(errorTrackingEnabled: true);
        $kernel = $this->bootKernel();

        self::assertTrue($kernel->container()->has(ErrorAggregator::class));
        self::assertTrue($kernel->container()->has(SensitiveDataScrubber::class));
    }

    #[Test]
    public function bootIsIdempotentWithConfig(): void
    {
        $this->writeObservabilityConfig();
        $configManager = new ConfigManager(configPath: $this->tempDir);
        $kernel = new Kernel(configManager: $configManager);

        $kernel->boot();
        $kernel->boot(); // Should not throw

        self::assertTrue($kernel->booted);
    }

    private function writeObservabilityConfig(
        bool $metricsEnabled = true,
        bool $exporterEnabled = false,
        string $exporterEndpoint = '/metrics',
        bool $tracingEnabled = false,
        bool $errorTrackingEnabled = true,
    ): void {
        $exporterEnabledStr = $exporterEnabled ? 'true' : 'false';
        $metricsEnabledStr = $metricsEnabled ? 'true' : 'false';
        $tracingEnabledStr = $tracingEnabled ? 'true' : 'false';
        $errorTrackingEnabledStr = $errorTrackingEnabled ? 'true' : 'false';

        $content = <<<PHP
            <?php
            return [
                'metrics' => [
                    'enabled' => {$metricsEnabledStr},
                    'exporters' => [
                        'openmetrics' => [
                            'enabled' => {$exporterEnabledStr},
                            'endpoint' => '{$exporterEndpoint}',
                        ],
                    ],
                ],
                'tracing' => [
                    'enabled' => {$tracingEnabledStr},
                    'sampling_rate' => 1.0,
                ],
                'error_tracking' => [
                    'enabled' => {$errorTrackingEnabledStr},
                ],
            ];
            PHP;

        file_put_contents($this->tempDir . '/observability.php', $content);
    }

    private function bootKernel(): Kernel
    {
        $configManager = new ConfigManager(configPath: $this->tempDir);
        $kernel = new Kernel(configManager: $configManager);
        $kernel->boot();

        return $kernel;
    }

    private function removeDir(string $dir): void
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
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
