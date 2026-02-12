<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\Bridge\WorkerInterface;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Runtime\RoadRunnerRuntime;
use Pulsar\Runtime\RuntimeStatus;
use Pulsar\Runtime\RuntimeType;
use Pulsar\Runtime\Worker\HealthStatus;
use RuntimeException;

#[CoversClass(RoadRunnerRuntime::class)]
final class RoadRunnerRuntimeTest extends TestCase
{
    /** @var KernelInterface&MockObject */
    private KernelInterface $kernel;
    private RequestSandbox $sandbox;
    private RuntimeConfig $config;
    /** @var WorkerInterface&MockObject */
    private WorkerInterface $worker;

    protected function setUp(): void
    {
        $this->kernel = $this->createMock(KernelInterface::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $this->sandbox = new RequestSandbox(
            $container,
            new RequestResetRegistry(),
            new LeakDetector(),
        );
        $this->config = new RuntimeConfig();
        $this->worker = $this->createMock(WorkerInterface::class);
    }

    #[Test]
    public function it_starts_with_stopped_status(): void
    {
        $runtime = $this->createRuntime();

        self::assertSame(RuntimeStatus::Stopped, $runtime->status());
    }

    #[Test]
    public function stop_sets_status_to_stopping(): void
    {
        $runtime = $this->createRuntime();
        $runtime->stop();

        self::assertSame(RuntimeStatus::Stopping, $runtime->status());
    }

    #[Test]
    public function reload_sets_status_to_draining(): void
    {
        $runtime = $this->createRuntime();
        $runtime->reload();

        self::assertSame(RuntimeStatus::Draining, $runtime->status());
    }

    #[Test]
    public function health_status_defaults_to_shutting_down_before_start(): void
    {
        $runtime = $this->createRuntime();

        // WorkerContext starts in Stopped state, which maps to ShuttingDown
        self::assertSame(HealthStatus::ShuttingDown, $runtime->healthStatus());
    }

    #[Test]
    public function health_status_returns_draining_after_reload(): void
    {
        $runtime = $this->createRuntime();
        $runtime->reload();

        self::assertSame(HealthStatus::Draining, $runtime->healthStatus());
    }

    #[Test]
    public function worker_info_returns_roadrunner_type(): void
    {
        $runtime = $this->createRuntime();
        $info = $runtime->workerInfo();

        self::assertSame(RuntimeType::RoadRunner, $info->runtimeType);
    }

    #[Test]
    public function before_request_delegates_to_sandbox(): void
    {
        $runtime = $this->createRuntime();
        $psrRequest = $this->createPsrRequest('/');

        $result = $runtime->beforeRequest($psrRequest);

        // Returns the same PSR-7 request (sandbox passes through)
        self::assertSame($psrRequest, $result);
    }

    #[Test]
    public function after_request_delegates_to_sandbox(): void
    {
        $runtime = $this->createRuntime();
        $psrRequest = $this->createPsrRequest('/');
        $psrResponse = $this->createPsrResponse(200, 'ok');

        // Should not throw -- sandbox cleanup runs without issues
        $this->expectNotToPerformAssertions();
        $runtime->afterRequest($psrRequest, $psrResponse);
    }

    #[Test]
    public function start_processes_requests_from_worker(): void
    {
        $psrRequest = $this->createPsrRequest('/test');
        $psrResponse = $this->createMock(ResponseInterface::class);

        // Worker returns one request then null to stop
        $this->worker->method('waitRequest')
            ->willReturnOnConsecutiveCalls($psrRequest, null);

        // Kernel handles the request and returns PSR-7 response
        $this->kernel->method('handle')
            ->willReturn($psrResponse);

        // Worker should receive a response
        $this->worker->expects(self::once())
            ->method('respond')
            ->with($psrResponse);

        $runtime = $this->createRuntime();
        $runtime->start();

        // After start() completes, status should be Stopped
        self::assertSame(RuntimeStatus::Stopped, $runtime->status());
    }

    #[Test]
    public function start_calls_kernel_boot_once(): void
    {
        // Worker returns null immediately
        $this->worker->method('waitRequest')
            ->willReturn(null);

        $this->kernel->expects(self::once())
            ->method('boot');

        $this->kernel->expects(self::once())
            ->method('shutdown');

        $runtime = $this->createRuntime();
        $runtime->start();
    }

    #[Test]
    public function start_handles_kernel_exceptions_gracefully(): void
    {
        $psrRequest = $this->createPsrRequest('/error');

        // Worker returns one request then null
        $this->worker->method('waitRequest')
            ->willReturnOnConsecutiveCalls($psrRequest, null);

        // Kernel throws
        $this->kernel->method('handle')
            ->willThrowException(new RuntimeException('Kernel error'));

        // Worker should still receive an error response (500)
        $this->worker->expects(self::once())
            ->method('respond')
            ->with(self::callback(static function (ResponseInterface $response): bool {
                return $response->getStatusCode() === 500;
            }));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('error');

        $runtime = new RoadRunnerRuntime(
            $this->kernel,
            $this->sandbox,
            $this->config,
            $this->worker,
            $logger,
        );

        $runtime->start();

        self::assertSame(RuntimeStatus::Stopped, $runtime->status());
    }

    #[Test]
    public function start_serves_health_endpoint(): void
    {
        $psrRequest = $this->createPsrRequest('/_health');

        // Worker returns health request then null
        $this->worker->method('waitRequest')
            ->willReturnOnConsecutiveCalls($psrRequest, null);

        // Worker should respond with a health response
        $this->worker->expects(self::once())
            ->method('respond')
            ->with(self::isInstanceOf(ResponseInterface::class));

        // Kernel should NOT be called for health endpoint
        $this->kernel->expects(self::never())
            ->method('handle');

        $config = new RuntimeConfig(healthEndpoint: true);

        $runtime = new RoadRunnerRuntime(
            $this->kernel,
            $this->sandbox,
            $config,
            $this->worker,
        );

        $runtime->start();
    }

    #[Test]
    public function start_skips_health_endpoint_when_disabled(): void
    {
        $psrRequest = $this->createPsrRequest('/_health');
        $psrResponse = $this->createMock(ResponseInterface::class);

        // Worker returns health request then null
        $this->worker->method('waitRequest')
            ->willReturnOnConsecutiveCalls($psrRequest, null);

        // Kernel SHOULD be called when health endpoint is disabled
        $this->kernel->expects(self::once())
            ->method('handle')
            ->willReturn($psrResponse);

        $config = new RuntimeConfig(healthEndpoint: false);

        $runtime = new RoadRunnerRuntime(
            $this->kernel,
            $this->sandbox,
            $config,
            $this->worker,
        );

        $runtime->start();
    }

    private function createRuntime(?LoggerInterface $logger = null): RoadRunnerRuntime
    {
        return new RoadRunnerRuntime(
            $this->kernel,
            $this->sandbox,
            $this->config,
            $this->worker,
            $logger,
        );
    }

    private function createPsrRequest(string $path): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getQuery')->willReturn('');
        $uri->method('__toString')->willReturn($path);

        $body = $this->createMock(StreamInterface::class);
        $body->method('__toString')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->method('getBody')->willReturn($body);
        $request->method('getHeaders')->willReturn([]);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getCookieParams')->willReturn([]);
        $request->method('getServerParams')->willReturn([]);
        $request->method('getProtocolVersion')->willReturn('1.1');
        $request->method('getParsedBody')->willReturn(null);

        return $request;
    }

    private function createPsrResponse(int $statusCode, string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
