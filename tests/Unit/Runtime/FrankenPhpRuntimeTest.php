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
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Http\Message\BodyTooLargeException;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\Exception\RuntimeException;
use Pulsar\Runtime\FrankenPhpRuntime;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Runtime\RuntimeStatus;
use Pulsar\Runtime\RuntimeType;
use Pulsar\Runtime\Worker\WorkerHealthStatus;
use ReflectionMethod;
use Throwable;

use function preg_replace;
use function strip_tags;

#[CoversClass(FrankenPhpRuntime::class)]
#[CoversClass(ProductionRenderer::class)]
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

    /**
     * `ServerRequest::fromGlobals()` used to run before the try block inside the
     * worker callback, so a body over the cap escaped the callback and killed
     * the worker — every in-flight and queued request on that process, from one
     * request nobody had to authenticate to send. Inside the guard it becomes a
     * response, and this is the response.
     */
    #[Test]
    public function oversized_body_yields_generic_413_rather_than_killing_the_worker(): void
    {
        $response = $this->errorResponse(BodyTooLargeException::exceedsLimit(10_485_761, 10_485_760));

        self::assertSame(ResponseStatus::PayloadTooLarge->value, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('BodyTooLargeException', $body);
        self::assertStringNotContainsString('10485761', $body);
        self::assertNoPathSeparatorInText($body);
    }

    /**
     * The previous error branch replied with a plain-text `Internal Server
     * Error` and no headers beyond the status. A response the middleware
     * pipeline never touched has to bring its own.
     */
    #[Test]
    public function handler_failure_yields_generic_500_with_its_own_security_headers(): void
    {
        $response = $this->errorResponse(
            RuntimeException::fatalError('database at 10.0.0.7 refused the connection'),
        );

        self::assertSame(ResponseStatus::InternalServerError->value, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('10.0.0.7', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertNoPathSeparatorInText($body);
    }

    private function errorResponse(Throwable $e): ResponseInterface
    {
        /** @var ResponseInterface */
        return new ReflectionMethod($this->runtime, 'errorResponse')->invoke($this->runtime, $e);
    }

    /**
     * The closing tags of the generic page are the only slashes it may contain.
     * Drop the inline stylesheet and the markup, and no separator of either
     * platform may survive in what is left.
     */
    private static function assertNoPathSeparatorInText(string $html): void
    {
        $withoutStyle = (string) preg_replace('#<style\b[^>]*>.*?</style>#s', '', $html);
        $text = strip_tags($withoutStyle);

        self::assertStringNotContainsString('/', $text, 'Rendered error text contains a path separator');
        self::assertStringNotContainsString('\\', $text, 'Rendered error text contains a path separator');
    }
}
