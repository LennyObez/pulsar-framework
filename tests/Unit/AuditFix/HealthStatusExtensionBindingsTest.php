<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\HealthStatus\Config\HealthStatusConfig;
use Pulsar\Extension\HealthStatus\Contracts\HealthCheckRunnerInterface;
use Pulsar\Extension\HealthStatus\Contracts\IntegrityVerificationRunnerInterface;
use Pulsar\Extension\HealthStatus\HealthStatusExtension;
use Pulsar\Extension\HealthStatus\Internal\Adapter\CoreHealthCheckRunnerAdapter;
use Pulsar\Extension\HealthStatus\Internal\Adapter\CoreIntegrityVerificationAdapter;
use Pulsar\Integrity\ManifestVerifierInterface;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface as CoreHealthCheckRunnerInterface;
use RuntimeException;

/**
 * Verifies that HealthStatusExtension::register() binds the adapter
 * interfaces when core services are available.
 */
#[CoversClass(HealthStatusExtension::class)]
#[CoversClass(CoreHealthCheckRunnerAdapter::class)]
#[CoversClass(CoreIntegrityVerificationAdapter::class)]
final class HealthStatusExtensionBindingsTest extends TestCase
{
    #[Test]
    public function registerBindsHealthCheckRunnerWhenCoreAvailable(): void
    {
        $bindings = [];
        $container = $this->createStub(ContainerInterface::class);

        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => match ($id) {
                HealthStatusConfig::class => false,
                \Pulsar\Config\ConfigManagerInterface::class => false,
                CoreHealthCheckRunnerInterface::class => true,
                ManifestVerifierInterface::class => false,
                default => false,
            },
        );

        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                HealthStatusConfig::class => new HealthStatusConfig(),
                default => throw new RuntimeException("Unexpected get: $id"),
            },
        );

        $container->method('instance')->willReturnCallback(
            static function (string $id, object $instance) use (&$bindings): void {
                $bindings[$id] = $instance;
            },
        );

        $container->method('bind')->willReturnCallback(
            static function (string $id, callable $factory) use (&$bindings): void {
                $bindings[$id] = $factory;
            },
        );

        $extension = new HealthStatusExtension();
        $extension->register($container);

        self::assertArrayHasKey(HealthCheckRunnerInterface::class, $bindings);
        self::assertArrayNotHasKey(IntegrityVerificationRunnerInterface::class, $bindings);
    }

    #[Test]
    public function registerBindsIntegrityRunnerWhenCoreVerifierAvailable(): void
    {
        $bindings = [];
        $container = $this->createStub(ContainerInterface::class);

        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => match ($id) {
                HealthStatusConfig::class => false,
                \Pulsar\Config\ConfigManagerInterface::class => false,
                CoreHealthCheckRunnerInterface::class => false,
                ManifestVerifierInterface::class => true,
                default => false,
            },
        );

        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                HealthStatusConfig::class => new HealthStatusConfig(),
                default => throw new RuntimeException("Unexpected get: $id"),
            },
        );

        $container->method('instance')->willReturnCallback(
            static function (string $id, object $instance) use (&$bindings): void {
                $bindings[$id] = $instance;
            },
        );

        $container->method('bind')->willReturnCallback(
            static function (string $id, callable $factory) use (&$bindings): void {
                $bindings[$id] = $factory;
            },
        );

        $extension = new HealthStatusExtension();
        $extension->register($container);

        self::assertArrayHasKey(IntegrityVerificationRunnerInterface::class, $bindings);
        self::assertArrayNotHasKey(HealthCheckRunnerInterface::class, $bindings);
    }
}
