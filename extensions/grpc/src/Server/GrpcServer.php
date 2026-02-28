<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Server;

use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Api;
use Pulsar\Extension\Grpc\Adapter\GrpcTransportAdapterInterface;
use Pulsar\Extension\Grpc\Config\GrpcConfig;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Interceptor\CallContext;
use Pulsar\Extension\Grpc\Interceptor\InterceptorPipeline;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use RuntimeException;

use function substr;

/**
 * Main gRPC server.
 *
 * Wraps the transport adapter, integrates with the interceptor pipeline,
 * and dispatches incoming gRPC requests to registered service handlers.
 * Implements GrpcServerInterface so it can be passed directly to the adapter.
 */
#[Api(since: '1.0.0')]
final readonly class GrpcServer implements GrpcServerInterface
{
    private RequestDispatcher $dispatcher;

    private LoggerInterface $logger;

    public function __construct(
        private GrpcConfig $config,
        private ServiceRegistryInterface $registry,
        private GrpcTransportAdapterInterface $adapter,
        InterceptorPipeline $pipeline,
        ?LoggerInterface $logger = null,
    ) {
        $this->dispatcher = new RequestDispatcher($pipeline);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Start the gRPC server.
     *
     * This is a blocking call that runs the transport event loop.
     * The server listens on the configured host and port and dispatches
     * incoming requests through the interceptor pipeline to service handlers.
     */
    public function start(): void
    {
        if ($this->registry->isEmpty()) {
            throw new RuntimeException(
                'Cannot start gRPC server: no services registered. '
                . 'Register at least one service via the ServiceRegistryInterface before starting.',
            );
        }

        if (!$this->adapter->isAvailable()) {
            throw new RuntimeException(
                'Cannot start gRPC server: transport adapter "'
                . $this->adapter->name()
                . '" is not available. Check that the required extension or library is installed.',
            );
        }

        $this->adapter->listen($this->config->host, $this->config->port, $this);
    }

    /**
     * Stop the gRPC server.
     */
    public function stop(): void
    {
        $this->adapter->shutdown();
    }

    /**
     * Handle an incoming gRPC request from the transport adapter.
     *
     * Resolves the target method and handler from the registry, builds
     * a CallContext, and dispatches through the interceptor pipeline.
     */
    #[Override]
    public function handle(
        string $fullMethodName,
        string $payload,
        array $metadata = [],
        ?string $peerIdentity = null,
    ): InterceptorResult {
        $resolved = $this->registry->resolve($fullMethodName);

        if ($resolved === null) {
            $this->logger->debug('gRPC method not found', ['method' => $fullMethodName]);

            return InterceptorResult::error(
                GrpcStatus::Unimplemented,
                'Method not implemented',
            );
        }

        [$method, $handler] = $resolved;

        $deadline = $this->extractDeadline($metadata);

        $context = new CallContext(
            method: $method,
            payload: $payload,
            metadata: $metadata,
            deadline: $deadline,
            peerIdentity: $peerIdentity,
        );

        return $this->dispatcher->dispatch($context, $handler);
    }

    /**
     * Extract the deadline from gRPC metadata (grpc-timeout header).
     *
     * @param array<string, list<string>> $metadata
     */
    private function extractDeadline(array $metadata): ?float
    {
        $timeout = $metadata['grpc-timeout'] ?? [];
        $value = $timeout[0] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        $unit = substr($value, -1);
        $amount = (float) substr($value, 0, -1);

        $seconds = match ($unit) {
            'H' => $amount * 3600.0,
            'M' => $amount * 60.0,
            'S' => $amount,
            'm' => $amount / 1_000.0,
            'u' => $amount / 1_000_000.0,
            'n' => $amount / 1_000_000_000.0,
            default => null,
        };

        if ($seconds === null) {
            return null;
        }

        return microtime(true) + $seconds;
    }
}
