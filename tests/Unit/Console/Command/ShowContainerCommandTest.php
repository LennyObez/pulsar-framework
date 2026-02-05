<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Command\ShowContainerCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;

#[CoversClass(ShowContainerCommand::class)]
final class ShowContainerCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $kernel = $this->createStub(KernelInterface::class);
        $command = new ShowContainerCommand($kernel);

        self::assertSame('show:container', $command->name);
    }

    #[Test]
    public function displaysBindings(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([
            'App\\Service\\UserService',
            'App\\Service\\OrderService',
        ]);
        $container->method('getInstances')->willReturn([
            'App\\Service\\UserService',
        ]);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('container')->willReturn($container);

        $command = new ShowContainerCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('show:container'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Container Bindings (2):', $output->buffer);
        self::assertStringContainsString('UserService', $output->buffer);
        self::assertStringContainsString('OrderService', $output->buffer);
        self::assertStringContainsString('Cached Instances (1):', $output->buffer);
    }

    #[Test]
    public function noBindings(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([]);
        $container->method('getInstances')->willReturn([]);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('container')->willReturn($container);

        $command = new ShowContainerCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('show:container'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('No bindings found', $output->buffer);
        self::assertStringContainsString('No cached instances', $output->buffer);
    }

    #[Test]
    public function filterApplied(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([
            'App\\Service\\UserService',
            'App\\Service\\OrderService',
            'App\\Repository\\UserRepository',
        ]);
        $container->method('getInstances')->willReturn([]);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('container')->willReturn($container);

        $command = new ShowContainerCommand($kernel);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('show:container', [], ['filter' => 'Order']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Container Bindings (1):', $output->buffer);
        self::assertStringContainsString('OrderService', $output->buffer);
        self::assertStringNotContainsString('UserService', $output->buffer);
        self::assertStringNotContainsString('UserRepository', $output->buffer);
    }
}
