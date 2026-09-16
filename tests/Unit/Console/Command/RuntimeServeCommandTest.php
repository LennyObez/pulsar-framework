<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Command;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Config\Environment;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Console\Command\RuntimeServeCommand;
use Pulsar\Console\ExitCode;
use Pulsar\Console\Input\ArrayInput;
use Pulsar\Console\Output\BufferedOutput;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\PersistentRuntimeFactoryInterface;
use Pulsar\Runtime\RuntimeCollectorInterface;
use Pulsar\Runtime\RuntimeInterface;
use Pulsar\Runtime\RuntimeResolver;
use Pulsar\Runtime\RuntimeType;
use Pulsar\Runtime\Upgrade\UpgradeContext;

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

    private function createFactory(?RuntimeInterface $runtime = null): PersistentRuntimeFactoryInterface
    {
        $kernelContainer = $this->createStub(ContainerInterface::class);
        $this->kernel->method('container')->willReturn($kernelContainer);

        $factory = $this->createStub(PersistentRuntimeFactoryInterface::class);

        if ($runtime !== null) {
            $factory->method('createForType')->willReturn($runtime);
            $factory->method('create')->willReturn($runtime);
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

    #[Test]
    public function hostnameWithoutPublicFails(): void
    {
        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: 'my-server.local', port: 8080);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('runtime:serve', [], ['runtime' => 'fpm']),
            $output,
        );

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('--public', $output->errorBuffer);
        self::assertStringContainsString('hostname resolution', $output->errorBuffer);
    }

    #[Test]
    public function localhostIsAllowedWithoutPublicFlag(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: 'localhost', port: 8080);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:serve'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
    }

    #[Test]
    public function ipv6LoopbackIsAllowed(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '::1', port: 8080);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:serve'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
    }

    #[Test]
    public function portAboveRangeReturnsError(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '127.0.0.1', port: 70000);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:serve'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('Invalid port', $output->errorBuffer);
    }

    #[Test]
    public function cliPortOptionOverridesConfig(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '127.0.0.1', port: 3000);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(
            new ArrayInput('runtime:serve', [], ['port' => '9090']),
            $output,
        );

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('127.0.0.1:9090', $output->buffer);
    }

    #[Test]
    public function concurrencyDisplaysAsSync(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '127.0.0.1', port: 8080, fiberConcurrency: 0);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:serve'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('concurrency: sync', $output->buffer);
    }

    #[Test]
    public function concurrencyDisplaysNumericValue(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $runtime = $this->createStub(RuntimeInterface::class);
        $factory = $this->createFactory($runtime);
        $config = new RuntimeConfig(host: '127.0.0.1', port: 8080, fiberConcurrency: 1);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:serve'), $output);

        self::assertSame(ExitCode::Success->value, $exit);
        self::assertStringContainsString('concurrency: 1', $output->buffer);
    }

    #[Test]
    public function concurrencyAboveOneIsRefusedForThePersistentRuntime(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        // The runtime cannot isolate two requests that interleave, so it does
        // not interleave them. Reported as a command error rather than an
        // uncaught exception out of the runtime constructor.
        $factory = $this->createFactory($this->createStub(RuntimeInterface::class));
        $config = new RuntimeConfig(host: '127.0.0.1', port: 8080, fiberConcurrency: 16);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        $exit = $command->execute(new ArrayInput('runtime:serve'), $output);

        self::assertSame(ExitCode::Error->value, $exit);
        self::assertStringContainsString('fiber_concurrency=16 is refused', $output->errorBuffer);
        self::assertStringContainsString('SessionManager', $output->errorBuffer);
        self::assertStringContainsString('worker processes', $output->errorBuffer);
        self::assertSame('', $output->buffer, 'the worker must not announce that it is starting');
    }

    #[Test]
    public function theRefusalIsRaisedBeforeTheRuntimeIsBuilt(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        // A factory that would hand back a runtime is never consulted: the
        // command must not reach construction, and must not call start().
        $factory = $this->createStub(PersistentRuntimeFactoryInterface::class);
        $factory->method('createForType')->willThrowException(
            new LogicException('the factory must not be reached'),
        );

        $config = new RuntimeConfig(host: '127.0.0.1', port: 8080, fiberConcurrency: 4);

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $config);
        $output = new BufferedOutput();

        self::assertSame(
            ExitCode::Error->value,
            $command->execute(new ArrayInput('runtime:serve'), $output),
        );
    }

    /**
     * The command rebuilds {@see RuntimeConfig} to fold in its options. A field
     * the rebuild omits is not left alone — it is reset to the constructor
     * default. `driver`, `drain_timeout_seconds` and `health_endpoint` were all
     * omitted, so `config/runtime.php` could ask for a 90-second drain and get a
     * 30-second one every time the worker was started, with nothing printed.
     *
     * One test per field, so each of the three is observed on its own rather
     * than hidden behind whichever assertion happens to fail first.
     */
    #[Test]
    public function theConfiguredDriverSurvivesTheRebuild(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $base = new RuntimeConfig(host: '127.0.0.1', port: 8080, driver: 'frankenphp');

        $captured = $this->runAndCaptureConfig($base, ['port' => '9090']);

        // The command-line override still applies; the base field is not lost.
        self::assertSame(9090, $captured->port);
        self::assertSame('frankenphp', $captured->driver, 'the configured driver was reset to "auto"');
    }

    #[Test]
    public function theConfiguredDrainTimeoutSurvivesTheRebuild(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $base = new RuntimeConfig(host: '127.0.0.1', port: 8080, drainTimeoutSeconds: 90);

        $captured = $this->runAndCaptureConfig($base, []);

        self::assertSame(90, $captured->drainTimeoutSeconds, 'the configured drain timeout was reset to 30');
    }

    #[Test]
    public function theDisabledHealthEndpointSurvivesTheRebuild(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $base = new RuntimeConfig(host: '127.0.0.1', port: 8080, healthEndpoint: false);

        $captured = $this->runAndCaptureConfig($base, []);

        self::assertFalse($captured->healthEndpoint, 'the disabled /_health endpoint was re-enabled');
    }

    /**
     * Unknown-key reporting (ADR-0036) travels on the DTO. A rebuild that drops
     * it hands the runtime a config claiming `config/runtime.php` was clean.
     */
    #[Test]
    public function unknownConfigKeysSurviveTheRebuild(): void
    {
        if (!extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets required');
        }

        $base = new RuntimeConfig(host: '127.0.0.1', port: 8080, unknownKeys: ['drain_timout_seconds']);

        $captured = $this->runAndCaptureConfig($base, []);

        self::assertSame(['drain_timout_seconds'], $captured->unknownConfigKeys());
    }

    /**
     * Run `runtime:serve` and return the {@see RuntimeConfig} it handed to the
     * runtime factory.
     *
     * @param array<string, string> $options Command-line options to pass
     */
    private function runAndCaptureConfig(RuntimeConfig $base, array $options): RuntimeConfig
    {
        $runtime = $this->createStub(RuntimeInterface::class);
        $this->kernel->method('container')->willReturn($this->createStub(ContainerInterface::class));

        $captured = null;
        $factory = $this->createStub(PersistentRuntimeFactoryInterface::class);
        $factory->method('createForType')->willReturnCallback(
            static function (
                RuntimeType $type,
                KernelInterface $kernel,
                RuntimeConfig $config,
                ?LoggerInterface $logger = null,
                ?RuntimeCollectorInterface $collector = null,
                ?UpgradeContext $upgradeContext = null,
            ) use (&$captured, $runtime): RuntimeInterface {
                $captured = $config;

                return $runtime;
            },
        );

        $command = new RuntimeServeCommand($this->kernel, $factory, $this->resolver, $base);
        $output = new BufferedOutput();

        self::assertSame(
            ExitCode::Success->value,
            $command->execute(new ArrayInput('runtime:serve', [], $options), $output),
            $output->errorBuffer,
        );

        self::assertInstanceOf(RuntimeConfig::class, $captured, 'the factory was never handed a config');

        return $captured;
    }
}
