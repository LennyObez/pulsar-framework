<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extension\OpenTelemetry\Config\OpenTelemetryConfig;
use Pulsar\Extension\OpenTelemetry\Noop\NoopLogSink;
use Pulsar\Extension\OpenTelemetry\Noop\NoopSpanProcessor;
use Pulsar\Extension\OpenTelemetry\OpenTelemetryExtension;

#[CoversClass(OpenTelemetryExtension::class)]
final class OpenTelemetryExtensionTest extends TestCase
{
    #[Test]
    public function extensionName(): void
    {
        $ext = new OpenTelemetryExtension();

        self::assertSame('pulsar/opentelemetry', $ext->name());
    }

    #[Test]
    public function implementsRequiredInterfaces(): void
    {
        $ext = new OpenTelemetryExtension();

        self::assertInstanceOf(ExtensionInterface::class, $ext);
        self::assertInstanceOf(PreBootExtensionInterface::class, $ext);
        self::assertInstanceOf(PostBootExtensionInterface::class, $ext);
    }

    #[Test]
    public function providersReturnsEmptyArray(): void
    {
        $ext = new OpenTelemetryExtension();

        self::assertSame([], $ext->providers());
    }

    #[Test]
    public function registerWithDisabledConfigRegistersNoopComponents(): void
    {
        $ext = new OpenTelemetryExtension();

        $registered = [];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('instance')->willReturnCallback(
            function (string $id, object $instance) use (&$registered): void {
                $registered[$id] = $instance;
            },
        );

        $ext->register($container);

        self::assertArrayHasKey(OpenTelemetryConfig::class, $registered);
        self::assertArrayHasKey(NoopSpanProcessor::class, $registered);
        self::assertArrayHasKey(NoopLogSink::class, $registered);
        self::assertInstanceOf(OpenTelemetryConfig::class, $registered[OpenTelemetryConfig::class]);
        self::assertFalse($registered[OpenTelemetryConfig::class]->enabled);
    }

    #[Test]
    public function preBootDoesNotThrow(): void
    {
        $ext = new OpenTelemetryExtension();
        $container = $this->createStub(ContainerInterface::class);

        // preBoot is intentionally empty
        $ext->preBoot($container);

        self::assertInstanceOf(OpenTelemetryExtension::class, $ext);
    }
}
