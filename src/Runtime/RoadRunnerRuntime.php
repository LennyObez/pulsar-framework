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
use Pulsar\Http\ResponseStatus;
use Pulsar\Runtime\Bridge\WorkerInterface;
use Pulsar\Runtime\Worker\HealthResponse;
use Pulsar\Runtime\Worker\WorkerContext;
use Pulsar\Runtime\Worker\WorkerHealthStatus;
use Pulsar\Runtime\Worker\WorkerInfo;
use Throwable;

use function function_exists;
use function sprintf;

use const PHP_OS_FAMILY;

/**
 * RoadRunner PSR-7 worker runtime adapter.
 *
 * Receives PSR-7 requests from RoadRunner's worker protocol,
 * processes through the kernel natively, and sends back
 * PSR-7 responses.
 */
#[Internal]
final class RoadRunnerRuntime implements ReloadableRuntimeInterface
{
    private RuntimeStatus $status = RuntimeStatus::Stopped;
    private readonly WorkerContext $workerContext;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly RequestSandbox $sandbox,
        private readonly RuntimeConfig $config,
        private readonly WorkerInterface $worker,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RuntimeCollectorInterface $collector = null,
    ) {
        $this->workerContext = new WorkerContext(RuntimeType::RoadRunner);
    }

    #[Override]
    public function start(): void
    {
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

        $this->logger?->info('RoadRunner worker started');

        // RoadRunner worker loop
        while ($this->isRunning()) {
            $psrRequest = $this->worker->waitRequest();

            if ($psrRequest === null) {
                break; // Worker told to stop by RoadRunner supervisor
            }

            $this->workerContext->beginRequest();

            // Health endpoint (bypass kernel)
            $path = $psrRequest->getUri()->getPath() ?: '/';

            if ($this->config->healthEndpoint && $path === '/_health') {
                $healthResponse = HealthResponse::fromWorkerInfo($this->workerInfo());
                $healthPsrResponse = new Response(
                    statusCode: $healthResponse->statusCode(),
                    headers: ['Content-Type' => ['application/json']],
                    body: $healthResponse->toJson(),
                );
                $this->worker->respond($healthPsrResponse);
                $this->workerContext->endRequest();

                continue;
            }

            // Process through sandbox and kernel
            $psrResponse = $this->processRequest($psrRequest);

            // Send PSR-7 response back to RoadRunner
            $this->worker->respond($psrResponse);
            $this->workerContext->endRequest();

            // Check recycling
            $recycleReason = $this->workerContext->shouldRecycle($this->config);

            if ($recycleReason !== null) {
                $this->emitRecycle($recycleReason);

                break;
            }
        }

        $this->shutdownGracefully();
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
        $this->logger?->info('RoadRunner worker reload requested: draining');
    }

    #[Override]
    public function healthStatus(): WorkerHealthStatus
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

    private function isRunning(): bool
    {
        return $this->status === RuntimeStatus::Running;
    }

    /**
     * Process a single request through sandbox and kernel.
     *
     * Both the sandbox and kernel operate on PSR-7 types natively.
     */
    private function processRequest(ServerRequestInterface $psrRequest): ResponseInterface
    {
        try {
            $psrRequest = $this->sandbox->beforeRequest($psrRequest);
            $psrResponse = $this->kernel->handle($psrRequest);
            $this->sandbox->afterRequest($psrRequest, $psrResponse);

            return $psrResponse;
        } catch (Throwable $e) {
            $path = $psrRequest->getUri()->getPath() ?: '/';
            $this->logger?->error('Request handler error', [
                'exception' => $e->getMessage(),
                'path' => $path,
            ]);

            $errorResponse = new Response(
                statusCode: ResponseStatus::InternalServerError->value,
                body: 'Internal Server Error',
            );

            $this->sandbox->afterRequest($psrRequest, $errorResponse);

            return $errorResponse;
        }
    }

    private function emitRecycle(string $reason): void
    {
        $info = $this->workerContext->info();
        $this->logger?->info(sprintf(
            'RoadRunner worker recycling: %s (requests: %d, memory: %dMB, uptime: %ds)',
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
        $this->logger?->info('RoadRunner worker stopped');
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

        $sigint = SIGINT;
        $sigterm = SIGTERM;
        $sigusr1 = SIGUSR1;

        pcntl_signal($sigint, function (): void {
            $this->stop();
        });

        pcntl_signal($sigterm, function (): void {
            $this->stop();
        });

        pcntl_signal($sigusr1, function (): void {
            $this->reload();
        });
    }
}
