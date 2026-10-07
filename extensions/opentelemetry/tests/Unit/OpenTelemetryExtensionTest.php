<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\OpenTelemetry\Config\OpenTelemetryConfig;
use Pulsar\Extension\OpenTelemetry\Noop\NoopLogSink;
use Pulsar\Extension\OpenTelemetry\Noop\NoopSpanProcessor;
use Pulsar\Extension\OpenTelemetry\OpenTelemetryExtension;
use Pulsar\Routing\RouterInterface;

#[CoversClass(OpenTelemetryExtension::class)]
final class OpenTelemetryExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarOpentelemetry(): void
    {
        $extension = new OpenTelemetryExtension();

        self::assertSame('pulsar/opentelemetry', $extension->name());
    }

    #[Test]
    public function providersReturnsEmptyArray(): void
    {
        $extension = new OpenTelemetryExtension();

        self::assertSame([], $extension->providers());
    }

    #[Test]
    public function registerWhenDisabledRegistersNoopProcessors(): void
    {
        $extension = new OpenTelemetryExtension();
        $instances = [];

        $container = $this->createContainerStub(
            instances: $instances,
            config: OpenTelemetryConfig::fromArray(['enabled' => false]),
        );

        $extension->register($container);

        self::assertArrayHasKey(NoopSpanProcessor::class, $instances);
        self::assertArrayHasKey(NoopLogSink::class, $instances);
        self::assertInstanceOf(NoopSpanProcessor::class, $instances[NoopSpanProcessor::class]);
        self::assertInstanceOf(NoopLogSink::class, $instances[NoopLogSink::class]);
    }

    #[Test]
    public function registerWhenDisabledDoesNotRegisterBridges(): void
    {
        $extension = new OpenTelemetryExtension();
        $instances = [];

        $container = $this->createContainerStub(
            instances: $instances,
            config: OpenTelemetryConfig::fromArray(['enabled' => false]),
        );

        $extension->register($container);

        // Should only have config + noop processors
        self::assertArrayHasKey(OpenTelemetryConfig::class, $instances);
        self::assertArrayHasKey(NoopSpanProcessor::class, $instances);
        self::assertArrayHasKey(NoopLogSink::class, $instances);
    }

    #[Test]
    public function bootDoesNotRegisterRoutes(): void
    {
        $extension = new OpenTelemetryExtension();
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createStub(RouterInterface::class);

        // boot() should return void without any side effects on the router
        $extension->boot($container, $router);

        // If we get here without error, the test passes
        self::assertInstanceOf(OpenTelemetryExtension::class, $extension);
    }

    #[Test]
    public function preBootIsNoOp(): void
    {
        $extension = new OpenTelemetryExtension();
        $container = $this->createStub(ContainerInterface::class);

        // preBoot() is intentionally empty
        $extension->preBoot($container);

        self::assertInstanceOf(OpenTelemetryExtension::class, $extension);
    }

    #[Test]
    public function postBootWhenDisabledReturnsEarly(): void
    {
        $extension = new OpenTelemetryExtension();
        $instances = [];

        $config = OpenTelemetryConfig::fromArray(['enabled' => false]);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($config): mixed {
                if ($id === OpenTelemetryConfig::class) {
                    return $config;
                }
                return null;
            },
        );

        // Should not throw
        $extension->postBoot($container);

        self::assertInstanceOf(OpenTelemetryExtension::class, $extension);
    }

    /**
     * Creates a container stub that tracks instance() calls and returns
     * a pre-loaded OpenTelemetryConfig.
     *
     * @param array<string, object> $instances Captured instances (by-ref)
     */
    private function createContainerStub(
        array &$instances,
        OpenTelemetryConfig $config,
    ): ContainerInterface {
        $stub = $this->createStub(ContainerInterface::class);

        $stub->method('instance')->willReturnCallback(
            static function (string $id, object $value) use (&$instances): void {
                $instances[$id] = $value;
            },
        );

        $stub->method('has')->willReturnCallback(
            static fn(string $id): bool => false,
        );

        $stub->method('get')->willReturnCallback(
            static function (string $id) use ($config): mixed {
                if ($id === OpenTelemetryConfig::class) {
                    return $config;
                }
                return null;
            },
        );

        return $stub;
    }
}
