<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\Bridge\WorkerInterface;
use Pulsar\Runtime\Exception\RuntimeException;
use Pulsar\Runtime\FpmRuntime;
use Pulsar\Runtime\PersistentRuntime;
use Pulsar\Runtime\RoadRunnerRuntime;
use Pulsar\Runtime\RuntimeFactory;
use Pulsar\Runtime\RuntimeType;

use function extension_loaded;

#[CoversClass(RuntimeFactory::class)]
final class RuntimeFactoryTest extends TestCase
{
    private ContainerInterface $container;
    private KernelInterface $kernel;

    protected function setUp(): void
    {
        $this->container = $this->createStub(ContainerInterface::class);

        $this->kernel = $this->createStub(KernelInterface::class);
        $kernelContainer = $this->createStub(ContainerInterface::class);
        $this->kernel->method('container')->willReturn($kernelContainer);
    }

    #[Test]
    public function create_for_type_fpm_returns_fpm_runtime(): void
    {
        $factory = new RuntimeFactory($this->container);

        $runtime = $factory->createForType(
            RuntimeType::Fpm,
            $this->kernel,
            new RuntimeConfig(),
        );

        self::assertInstanceOf(FpmRuntime::class, $runtime);
    }

    #[Test]
    public function create_for_type_persistent_returns_persistent_runtime(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $factory = new RuntimeFactory($this->container);

        $runtime = $factory->createForType(
            RuntimeType::Persistent,
            $this->kernel,
            new RuntimeConfig(),
        );

        self::assertInstanceOf(PersistentRuntime::class, $runtime);
    }

    #[Test]
    public function create_backward_compat_returns_persistent_runtime(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $factory = new RuntimeFactory($this->container);

        $runtime = $factory->create(
            $this->kernel,
            new RuntimeConfig(),
        );

        self::assertInstanceOf(PersistentRuntime::class, $runtime);
    }

    #[Test]
    public function create_for_type_roadrunner_throws_when_worker_not_registered(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $factory = new RuntimeFactory($container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WorkerInterface not registered');

        $factory->createForType(
            RuntimeType::RoadRunner,
            $this->kernel,
            new RuntimeConfig(),
        );
    }

    #[Test]
    public function create_for_type_roadrunner_succeeds_with_worker_registered(): void
    {
        $worker = $this->createStub(WorkerInterface::class);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === WorkerInterface::class,
        );
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => match ($id) {
                WorkerInterface::class => $worker,
                default => throw new \RuntimeException("Unexpected get: {$id}"),
            },
        );

        $factory = new RuntimeFactory($container);

        $runtime = $factory->createForType(
            RuntimeType::RoadRunner,
            $this->kernel,
            new RuntimeConfig(),
        );

        self::assertInstanceOf(RoadRunnerRuntime::class, $runtime);
    }
}
