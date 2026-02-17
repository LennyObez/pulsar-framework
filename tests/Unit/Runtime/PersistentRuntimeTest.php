<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\PersistentRuntime;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Runtime\RuntimeStatus;
use Pulsar\Runtime\Worker\WorkerHealthStatus;
use Pulsar\Runtime\Worker\WorkerState;

#[CoversClass(PersistentRuntime::class)]
#[RequiresPhpExtension('sockets')]
final class PersistentRuntimeTest extends TestCase
{
    private function createRuntime(?RuntimeConfig $config = null): PersistentRuntime
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('container')->willReturn($container);

        $sandbox = new RequestSandbox(
            $container,
            new RequestResetRegistry(),
            new LeakDetector(),
        );

        return new PersistentRuntime(
            kernel: $kernel,
            sandbox: $sandbox,
            config: $config ?? new RuntimeConfig(),
        );
    }

    #[Test]
    public function initialStatusIsStopped(): void
    {
        $runtime = $this->createRuntime();

        self::assertSame(RuntimeStatus::Stopped, $runtime->status());
    }

    #[Test]
    public function initialRequestCountIsZero(): void
    {
        $runtime = $this->createRuntime();

        self::assertSame(0, $runtime->requestCount());
    }

    #[Test]
    public function stopSetsStatusToStopping(): void
    {
        $runtime = $this->createRuntime();

        $runtime->stop();

        self::assertSame(RuntimeStatus::Stopping, $runtime->status());
    }

    #[Test]
    public function reloadSetsStatusToDraining(): void
    {
        $runtime = $this->createRuntime();

        $runtime->reload();

        self::assertSame(RuntimeStatus::Draining, $runtime->status());
    }

    #[Test]
    #[DataProvider('healthStatusMappingProvider')]
    public function healthStatusMapsFromRuntimeStatus(
        string $action,
        WorkerHealthStatus $expectedHealth,
    ): void {
        $runtime = $this->createRuntime();

        // Apply action to change internal status
        match ($action) {
            'none' => null, // Stopped
            'stop' => $runtime->stop(),
            'reload' => $runtime->reload(),
            default => self::fail("Unknown action: {$action}"),
        };

        self::assertSame($expectedHealth, $runtime->healthStatus());
    }

    /**
     * @return iterable<string, array{string, WorkerHealthStatus}>
     */
    public static function healthStatusMappingProvider(): iterable
    {
        yield 'stopped maps to ShuttingDown' => ['none', WorkerHealthStatus::ShuttingDown];
        yield 'stopping maps to ShuttingDown' => ['stop', WorkerHealthStatus::ShuttingDown];
        yield 'draining maps to Draining' => ['reload', WorkerHealthStatus::Draining];
    }

    #[Test]
    public function workerInfoReturnsValidSnapshot(): void
    {
        $runtime = $this->createRuntime();

        $info = $runtime->workerInfo();

        self::assertSame(0, $info->requestCount);
        self::assertGreaterThanOrEqual(0, $info->memoryUsageMb);
        self::assertSame(WorkerState::Stopped, $info->state);
    }

    #[Test]
    public function workerInfoReflectsStoppingState(): void
    {
        $runtime = $this->createRuntime();

        $runtime->stop();
        $info = $runtime->workerInfo();

        self::assertSame(WorkerState::Recycling, $info->state);
    }

    #[Test]
    public function workerInfoReflectsDrainingState(): void
    {
        $runtime = $this->createRuntime();

        $runtime->reload();
        $info = $runtime->workerInfo();

        self::assertSame(WorkerState::Draining, $info->state);
    }

    #[Test]
    public function beforeRequestDelegatesToSandbox(): void
    {
        $runtime = $this->createRuntime();

        $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $result = $runtime->beforeRequest($request);

        // Sandbox returns the same request (no transformation in default config)
        self::assertSame($request, $result);
    }

    #[Test]
    public function afterRequestDelegatesToSandbox(): void
    {
        $runtime = $this->createRuntime();

        $request = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $response = $this->createStub(\Psr\Http\Message\ResponseInterface::class);

        // Should not throw — sandbox cleanup runs without error
        $runtime->afterRequest($request, $response);

        self::assertSame(RuntimeStatus::Stopped, $runtime->status());
    }
}
