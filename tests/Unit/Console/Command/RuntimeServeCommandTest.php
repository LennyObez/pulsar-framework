<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Console\Command\RuntimeServeCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\PersistentRuntimeFactoryInterface;
use Pulsar\Runtime\RuntimeInterface;

use function extension_loaded;

#[CoversClass(RuntimeServeCommand::class)]
final class RuntimeServeCommandTest extends TestCase
{
    #[Test]
    public function configuredCorrectly(): void
    {
        $kernel = $this->createStub(KernelInterface::class);
        $factory = $this->createStub(PersistentRuntimeFactoryInterface::class);

        $command = new RuntimeServeCommand($kernel, $factory);

        self::assertSame('runtime:serve', $command->name);
    }

    #[Test]
    public function nonLoopbackWithoutPublicFails(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $kernel = $this->createStub(KernelInterface::class);
        $factory = $this->createStub(PersistentRuntimeFactoryInterface::class);
        $config = new RuntimeConfig(host: '0.0.0.0', port: 8080);

        $command = new RuntimeServeCommand($kernel, $factory, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:serve'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('--public', $output->errorBuffer);
    }

    #[Test]
    public function invalidPortReturnsError(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $kernel = $this->createStub(KernelInterface::class);
        $factory = $this->createStub(PersistentRuntimeFactoryInterface::class);
        $config = new RuntimeConfig(host: '127.0.0.1', port: 0);

        $command = new RuntimeServeCommand($kernel, $factory, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:serve'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Invalid port', $output->errorBuffer);
    }

    #[Test]
    public function loopbackHostAllowed(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $kernel = $this->createStub(KernelInterface::class);
        $runtime = $this->createStub(RuntimeInterface::class);

        $factory = $this->createStub(PersistentRuntimeFactoryInterface::class);
        $factory->method('create')->willReturn($runtime);

        $config = new RuntimeConfig(host: '127.0.0.1', port: 8080);

        $command = new RuntimeServeCommand($kernel, $factory, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:serve'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Runtime stopped gracefully', $output->buffer);
    }

    #[Test]
    public function nonLoopbackWithPublicFlagSucceeds(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $kernel = $this->createStub(KernelInterface::class);
        $runtime = $this->createStub(RuntimeInterface::class);

        $factory = $this->createStub(PersistentRuntimeFactoryInterface::class);
        $factory->method('create')->willReturn($runtime);

        $config = new RuntimeConfig(host: '0.0.0.0', port: 8080);

        $command = new RuntimeServeCommand($kernel, $factory, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:serve', [], ['public' => true]), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Runtime stopped gracefully', $output->buffer);
    }

    #[Test]
    public function cliHostOptionOverridesConfig(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $kernel = $this->createStub(KernelInterface::class);
        $runtime = $this->createStub(RuntimeInterface::class);

        $factory = $this->createStub(PersistentRuntimeFactoryInterface::class);
        $factory->method('create')->willReturn($runtime);

        $config = new RuntimeConfig(host: '0.0.0.0', port: 8080);

        $command = new RuntimeServeCommand($kernel, $factory, $config);
        $output = new BufferedOutput();

        // Override host via CLI option to a loopback address (no --public needed)
        $exit = $command->execute(
            new ArrayInput('runtime:serve', [], ['host' => '127.0.0.1']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
    }
}
