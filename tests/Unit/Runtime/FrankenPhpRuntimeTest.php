<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\Exception\RuntimeException;
use Pulsar\Runtime\FrankenPhpRuntime;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Runtime\RuntimeStatus;
use Pulsar\Runtime\RuntimeType;
use Pulsar\Runtime\Worker\WorkerHealthStatus;

#[CoversClass(FrankenPhpRuntime::class)]
final class FrankenPhpRuntimeTest extends TestCase
{
    private FrankenPhpRuntime $runtime;
    private RuntimeConfig $config;

    protected function setUp(): void
    {
        $kernel = $this->createStub(KernelInterface::class);
        $this->config = new RuntimeConfig();

        $container = $this->createStub(ContainerInterface::class);
        $registry = new RequestResetRegistry();
        $leakDetector = new LeakDetector();

        $sandbox = new RequestSandbox(
            container: $container,
            registry: $registry,
            leakDetector: $leakDetector,
        );

        $this->runtime = new FrankenPhpRuntime(
            kernel: $kernel,
            sandbox: $sandbox,
            config: $this->config,
        );
    }

    #[Test]
    public function initial_status_is_stopped(): void
    {
        self::assertSame(RuntimeStatus::Stopped, $this->runtime->status());
    }

    #[Test]
    public function stop_sets_status_to_stopping(): void
    {
        $this->runtime->stop();

        self::assertSame(RuntimeStatus::Stopping, $this->runtime->status());
    }

    #[Test]
    public function reload_sets_status_to_draining(): void
    {
        $this->runtime->reload();

        self::assertSame(RuntimeStatus::Draining, $this->runtime->status());
    }

    #[Test]
    public function health_status_is_shutting_down_when_stopped(): void
    {
        self::assertSame(WorkerHealthStatus::ShuttingDown, $this->runtime->healthStatus());
    }

    #[Test]
    public function health_status_returns_draining_after_reload(): void
    {
        $this->runtime->reload();

        self::assertSame(WorkerHealthStatus::Draining, $this->runtime->healthStatus());
    }

    #[Test]
    public function worker_info_returns_frankenphp_type(): void
    {
        $info = $this->runtime->workerInfo();

        self::assertSame(RuntimeType::FrankenPhp, $info->runtimeType);
    }

    #[Test]
    public function worker_info_has_zero_request_count_initially(): void
    {
        $info = $this->runtime->workerInfo();

        self::assertSame(0, $info->requestCount);
    }

    #[Test]
    public function before_request_delegates_to_sandbox(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);

        $result = $this->runtime->beforeRequest($request);

        self::assertSame($request, $result);
    }

    #[Test]
    public function after_request_delegates_to_sandbox(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        $this->runtime->afterRequest($request, $response);

        // afterRequest() performs sandbox cleanup; runtime status must remain unchanged
        self::assertSame(RuntimeStatus::Stopped, $this->runtime->status());
    }

    #[Test]
    public function start_throws_when_frankenphp_not_available(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('frankenphp');

        $this->runtime->start();
    }

    #[Test]
    public function stop_is_idempotent(): void
    {
        $this->runtime->stop();
        $this->runtime->stop();

        self::assertSame(RuntimeStatus::Stopping, $this->runtime->status());
    }

    #[Test]
    public function health_status_returns_shutting_down_after_stop(): void
    {
        $this->runtime->stop();

        self::assertSame(WorkerHealthStatus::ShuttingDown, $this->runtime->healthStatus());
    }
}
