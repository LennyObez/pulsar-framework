<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Analytics\AnalyticsExtension;
use Pulsar\Extension\Analytics\AnalyticsServiceProvider;
use Pulsar\Extension\Analytics\Config\AnalyticsConfig;
use Pulsar\Routing\RouterInterface;
use Pulsar\Scheduler\JobRegistryInterface;

use function in_array;

final class AnalyticsExtensionBootTest extends TestCase
{
    private AnalyticsExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new AnalyticsExtension();
    }

    #[Test]
    public function registerDoesNothing(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        // register() has an empty body; should not throw
        $this->ext->register($container);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function preBootUsesExistingConfig(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => $id === AnalyticsConfig::class);

        // When AnalyticsConfig already exists, preBoot should not try to load config
        $this->ext->preBoot($container);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function preBootCreatesDefaultConfigWhenNoneExists(): void
    {
        $instances = [];
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturn(false);
        $container->expects(self::atLeastOnce())
            ->method('instance')
            ->willReturnCallback(function (string $id, object $instance) use (&$instances): void {
                $instances[$id] = $instance;
            });

        $this->ext->preBoot($container);

        self::assertArrayHasKey(AnalyticsConfig::class, $instances);
        self::assertInstanceOf(AnalyticsConfig::class, $instances[AnalyticsConfig::class]);
    }

    #[Test]
    public function preBootLoadsConfigFromFileWhenAvailable(): void
    {
        $tmpDir = sys_get_temp_dir() . '/pulsar-test-' . uniqid();
        mkdir($tmpDir, 0o777, true);
        file_put_contents($tmpDir . '/analytics.php', '<?php return ["enabled" => false];');

        try {
            $configManager = $this->createStub(ConfigManagerInterface::class);
            $configManager->method('configPath')->willReturn($tmpDir);

            /** @var array<string, object> $instances */
            $instances = [];
            $container = $this->createMock(ContainerInterface::class);
            $container->method('has')
                ->willReturnCallback(static function (string $id) use (&$instances): bool {
                    if ($id === ConfigManagerInterface::class) {
                        return true;
                    }
                    return isset($instances[$id]);
                });
            $container->method('get')
                ->willReturnCallback(static function (string $id) use ($configManager, &$instances): mixed {
                    if ($id === ConfigManagerInterface::class) {
                        return $configManager;
                    }
                    return $instances[$id] ?? null;
                });
            $container->expects(self::atLeastOnce())
                ->method('instance')
                ->willReturnCallback(function (string $id, object $instance) use (&$instances): void {
                    $instances[$id] = $instance;
                });

            $this->ext->preBoot($container);

            self::assertArrayHasKey(AnalyticsConfig::class, $instances);
            $config = $instances[AnalyticsConfig::class];
            self::assertInstanceOf(AnalyticsConfig::class, $config);
            self::assertFalse($config->enabled);
        } finally {
            @unlink($tmpDir . '/analytics.php');
            @rmdir($tmpDir);
        }
    }

    #[Test]
    public function bootReturnsEarlyWhenDisabled(): void
    {
        $config = new AnalyticsConfig(enabled: false);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static fn(string $id): ?object => match ($id) {
                AnalyticsConfig::class => $config,
                default => null,
            });

        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('add');

        $this->ext->boot($container, $router);
    }

    #[Test]
    public function bootRegistersRoutes(): void
    {
        $config = new AnalyticsConfig(enabled: true);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static fn(string $id): ?object => match ($id) {
                AnalyticsConfig::class => $config,
                default => null,
            });
        $container->method('has')->willReturn(true);

        $router = $this->createMock(RouterInterface::class);
        // Should register multiple routes (public + API + dashboard)
        $router->expects(self::atLeast(10))->method('add')->willReturnSelf();

        $this->ext->boot($container, $router);
    }

    #[Test]
    public function postBootReturnsEarlyWhenDisabled(): void
    {
        $config = new AnalyticsConfig(enabled: false);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static fn(string $id): ?object => match ($id) {
                AnalyticsConfig::class => $config,
                default => null,
            });
        $container->method('has')->willReturn(true);

        $this->ext->postBoot($container);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function postBootRegistersSchedulerJobs(): void
    {
        $config = new AnalyticsConfig(enabled: true);
        /** @var list<string> $registered */
        $registered = [];
        $registry = $this->createStub(JobRegistryInterface::class);
        $registry->method('register')->willReturnCallback(
            static function (string $jobClass) use (&$registered): void {
                $registered[] = $jobClass;
            },
        );

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static fn(string $id): ?object => match ($id) {
                AnalyticsConfig::class => $config,
                JobRegistryInterface::class => $registry,
                default => null,
            });
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => in_array($id, [
                AnalyticsConfig::class,
                JobRegistryInterface::class,
            ], true));

        $this->ext->postBoot($container);

        self::assertCount(3, $registered);
    }

    #[Test]
    public function postBootSkipsSchedulerWhenRegistryNotAvailable(): void
    {
        $config = new AnalyticsConfig(enabled: true);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static fn(string $id): ?object => match ($id) {
                AnalyticsConfig::class => $config,
                default => null,
            });
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => $id === AnalyticsConfig::class);

        $this->ext->postBoot($container);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function nameReturnsPulsarAnalytics(): void
    {
        self::assertSame('pulsar/analytics', $this->ext->name());
    }

    #[Test]
    public function providersReturnsServiceProviderClass(): void
    {
        self::assertSame([AnalyticsServiceProvider::class], $this->ext->providers());
    }
}
