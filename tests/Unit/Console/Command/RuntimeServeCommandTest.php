<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Console\Command\RuntimeServeCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\RuntimeFactory;
use Pulsar\Runtime\RuntimeInterface;
use Pulsar\Runtime\RuntimeResolver;

use function extension_loaded;

#[CoversClass(RuntimeServeCommand::class)]
final class RuntimeServeCommandTest extends TestCase
{
    /** @var KernelInterface&\PHPUnit\Framework\MockObject\Stub */
    private KernelInterface $kernel;
    private RuntimeResolver $resolver;

    protected function setUp(): void
    {
        $this->kernel = $this->createStub(KernelInterface::class);
        $this->resolver = new RuntimeResolver(
            environment: Environment::load(null),
            frankenPhpDetector: static fn(): bool => false,
            roadRunnerDetector: static fn(): bool => false,
            socketsDetector: static fn(): bool => extension_loaded('sockets'),
        );
    }

    private function createFactory(?RuntimeInterface $runtime = null): RuntimeFactory
    {
        $container = $this->createStub(ContainerInterface::class);
        $kernelContainer = $this->createStub(ContainerInterface::class);
        $this->kernel->method('container')->willReturn($kernelContainer);

        $factory = $this->createStub(RuntimeFactory::class);

        if ($runtime !== null) {
            $factory->method('createForType')->willReturn($runtime);
        }

        return $factory;
    }

    #[Test]
    public function configuredCorrectly(): void
    {
        $factory = $this->createFactory();
        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver);

        self::assertSame('runtime:serve', $command->name);
    }

    #[Test]
    public function hasRuntimeOption(): void
    {
        $factory = $this->createFactory();
        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver);

        self::assertArrayHasKey('runtime', $command->options);
    }

    #[Test]
    public function nonLoopbackWithoutPublicFails(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '0.0.0.0', port: 8080);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
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

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '127.0.0.1', port: 0);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
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

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '127.0.0.1', port: 8080);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
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

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '0.0.0.0', port: 8080);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
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

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '0.0.0.0', port: 8080);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        // Override host via CLI option to a loopback address (no --public needed)
        $exit = $command->execute(
            new ArrayInput('runtime:serve', [], ['host' => '127.0.0.1']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
    }

    #[Test]
    public function runtimeOptionSelectsFpmRuntime(): void
    {
        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '127.0.0.1', port: 8080);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('runtime:serve', [], ['runtime' => 'fpm']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('Starting fpm runtime', $output->buffer);
    }

    #[Test]
    public function outputShowsRuntimeType(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '127.0.0.1', port: 8080);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        $command->execute(new ArrayInput('runtime:serve'), $output);

        // Default resolution should include the runtime type name in output
        self::assertStringContainsString('runtime on 127.0.0.1:8080', $output->buffer);
    }
}
