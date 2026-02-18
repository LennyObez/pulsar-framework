<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Core\KernelInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\Exception\RuntimeException;
use Pulsar\Runtime\Worker\HealthResponse;
use Pulsar\Runtime\Worker\HealthStatus;
use Pulsar\Runtime\Worker\WorkerContext;
use Pulsar\Runtime\Worker\WorkerInfo;
use Throwable;

use function function_exists;
use function sprintf;

use const PHP_OS_FAMILY;

/**
 * FrankenPHP worker mode runtime adapter.
 *
 * Uses frankenphp_handle_request() to process requests in a persistent
 * worker. The kernel is booted once, and each request goes through
 * the RequestSandbox for isolation.
 */
#[Internal]
final class FrankenPhpRuntime implements ReloadableRuntimeInterface
{
    private RuntimeStatus $status = RuntimeStatus::Stopped;
    private readonly WorkerContext $workerContext;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly RequestSandbox $sandbox,
        private readonly RuntimeConfig $config,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RuntimeCollectorInterface $collector = null,
    ) {
        $this->workerContext = new WorkerContext(RuntimeType::FrankenPhp);
    }

    #[Override]
    public function start(): void
    {
        if (!function_exists('frankenphp_handle_request')) {
            throw RuntimeException::extensionMissing('frankenphp');
        }

        $this->status = RuntimeStatus::Starting;
        $this->workerContext->boot();

        $this->kernel->boot();
        $this->registerSignalHandlers();

        $this->collector?->emitWorkerStart(
            host: $this->config->host,
            port: $this->config->port,
            fiberConcurrency: 0,
            maxRequests: $this->config->maxRequests,
            memoryThresholdMb: $this->config->memoryThresholdMb,
        );

        $this->status = RuntimeStatus::Running;
        $this->workerContext->ready();

        $this->logger?->info('FrankenPHP worker started');

        // FrankenPHP worker loop
        // @codeCoverageIgnoreStart
        $continueWorking = true;

        while ($continueWorking && $this->status === RuntimeStatus::Running) {
            $result = frankenphp_handle_request(function (): void {
                $this->handleRequest();
            });

            if (!$result) {
                break;
            }

            $recycleReason = $this->workerContext->shouldRecycle($this->config);

            if ($recycleReason !== null) {
                $this->emitRecycle($recycleReason);
                $continueWorking = false;
            }
        }

        $this->shutdownGracefully();
        // @codeCoverageIgnoreEnd
    }

    /**
     * Handle a single request inside the FrankenPHP worker callback.
     *
     * @codeCoverageIgnore Requires FrankenPHP runtime environment
     */
    private function handleRequest(): void
    {
        $this->workerContext->beginRequest();

        $request = ServerRequest::fromGlobals();

        // Health endpoint (bypass kernel)
        if ($this->config->healthEndpoint && $request->getUri()->getPath() === '/_health') {
            $this->emitHealthResponse();
            $this->workerContext->endRequest();

            return;
        }

        try {
            $request = $this->beforeRequest($request);
            $response = $this->kernel->handle($request);
        } catch (Throwable $e) {
            $this->logger?->error('Request handler error', [
                'exception' => $e->getMessage(),
                'path' => $request->getUri()->getPath(),
            ]);
            $response = new Response(
                statusCode: ResponseStatus::InternalServerError->value,
                body: 'Internal Server Error',
            );
        }

        $this->afterRequest($request, $response);
        $this->emitResponse($response);
        $this->workerContext->endRequest();
    }

    #[Override]
    public function stop(): void
    {
        $this->status = RuntimeStatus::Stopping;
    }

    #[Override]
    public function reload(): void
    {
        $this->status = RuntimeStatus::Draining;
        $this->workerContext->drain();
        $this->logger?->info('FrankenPHP worker reload requested — draining');
    }

    #[Override]
    public function healthStatus(): HealthStatus
    {
        return $this->workerContext->healthStatus();
    }

    #[Override]
    public function workerInfo(): WorkerInfo
    {
        return $this->workerContext->info();
    }

    #[Override]
    public function beforeRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        return $this->sandbox->beforeRequest($request);
    }

    #[Override]
    public function afterRequest(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $this->sandbox->afterRequest($request, $response);
    }

    public function status(): RuntimeStatus
    {
        return $this->status;
    }

    /**
     * Send early hints (103) via FrankenPHP's native API.
     *
     * @param array<string, string> $headers Headers to send as early hints
     *
     * @codeCoverageIgnore Requires FrankenPHP runtime
     */
    public function earlyHints(array $headers): void
    {
        if (function_exists('frankenphp_early_hints')) {
            frankenphp_early_hints($headers);
        }
    }

    /**
     * Emit a Response using standard PHP output functions.
     *
     * @codeCoverageIgnore Requires FrankenPHP runtime environment
     */
    private function emitResponse(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }

        echo $response->getBody();
    }

    /**
     * Emit health endpoint response.
     *
     * @codeCoverageIgnore Requires FrankenPHP runtime environment
     */
    private function emitHealthResponse(): void
    {
        $health = HealthResponse::fromWorkerInfo($this->workerInfo());
        http_response_code($health->statusCode());
        header('Content-Type: application/json');
        echo $health->toJson();
    }

    private function emitRecycle(string $reason): void
    {
        $info = $this->workerContext->info();
        $this->logger?->info(sprintf(
            'FrankenPHP worker recycling: %s (requests: %d, memory: %dMB, uptime: %ds)',
            $reason,
            $info->requestCount,
            $info->memoryUsageMb,
            $this->workerContext->uptimeSeconds(),
        ));

        $this->collector?->emitWorkerRecycle(
            reason: $reason,
            requestCount: $info->requestCount,
            memoryUsageMb: $info->memoryUsageMb,
            uptimeSeconds: $this->workerContext->uptimeSeconds(),
        );
    }

    private function shutdownGracefully(): void
    {
        $this->status = RuntimeStatus::Stopping;
        $this->workerContext->stop();
        $this->kernel->shutdown();
        $this->status = RuntimeStatus::Stopped;
        $this->logger?->info('FrankenPHP worker stopped');
    }

    /**
     * @codeCoverageIgnore POSIX-only
     */
    private function registerSignalHandlers(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if (!function_exists('pcntl_signal')) {
            return;
        }

        /** @psalm-suppress UndefinedConstant — POSIX-only, guarded by OS check */
        pcntl_signal(SIGINT, function (): void {
            $this->stop();
        });

        /** @psalm-suppress UndefinedConstant */
        pcntl_signal(SIGTERM, function (): void {
            $this->stop();
        });

        /** @psalm-suppress UndefinedConstant */
        pcntl_signal(SIGUSR1, function (): void {
            $this->reload();
        });
    }
}
