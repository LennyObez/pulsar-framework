<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\PersistentRuntimeFactory;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RuntimeInterface;
use RuntimeException;

#[CoversClass(PersistentRuntimeFactory::class)]
final class PersistentRuntimeFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsPersistentRuntime(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => match ($id) {
                RequestResetRegistry::class, LeakDetector::class => true,
                default => false,
            },
        );
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                RequestResetRegistry::class => new RequestResetRegistry(),
                LeakDetector::class => new LeakDetector(),
                default => throw new RuntimeException('Unexpected get: ' . $id),
            },
        );

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('container')->willReturn($container);

        $config = new RuntimeConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $factory = new PersistentRuntimeFactory($container);
        $runtime = $factory->create($kernel, $config);

        self::assertInstanceOf(RuntimeInterface::class, $runtime);
    }

    #[Test]
    public function createFallsBackToDefaultsWhenContainerLacksBindings(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('container')->willReturn($container);

        $config = new RuntimeConfig(
            host: '0.0.0.0',
            port: 9090,
        );

        $factory = new PersistentRuntimeFactory($container);
        $runtime = $factory->create($kernel, $config);

        self::assertInstanceOf(RuntimeInterface::class, $runtime);
    }
}
