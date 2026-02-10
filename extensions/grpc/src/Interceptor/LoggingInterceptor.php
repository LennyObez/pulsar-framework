<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Interceptor;

use Closure;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Grpc\Error\GrpcStatus;

use function hrtime;
use function round;

/**
 * Interceptor that logs gRPC call lifecycle events.
 *
 * Logs call start at DEBUG level, and call completion at INFO (success),
 * WARNING (client error), or ERROR (server error) level. Records method
 * name, gRPC status, duration, and client identity when available.
 */
#[Internal(reason: 'Pipeline implementation detail — use InterceptorPipeline')]
final readonly class LoggingInterceptor implements InterceptorInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function handle(CallContext $context, Closure $next): InterceptorResult
    {
        $method = $context->method->fullName;
        $identity = $context->attributes['auth.identity'] ?? $context->peerIdentity;

        $this->logger->debug('gRPC call started', [
            'method' => $method,
            'identity' => $identity,
        ]);

        $startTime = hrtime(true);

        $result = $next($context);

        $durationMs = round((hrtime(true) - $startTime) / 1_000_000, 2);

        $logContext = [
            'method' => $method,
            'status' => $result->status->name,
            'status_code' => $result->status->value,
            'duration_ms' => $durationMs,
            'identity' => $identity,
        ];

        if ($result->isOk()) {
            $this->logger->info('gRPC call completed', $logContext);
        } elseif ($this->isClientError($result->status)) {
            $this->logger->warning('gRPC call failed (client error)', [
                ...$logContext,
                'message' => $result->message,
            ]);
        } else {
            $this->logger->error('gRPC call failed (server error)', [
                ...$logContext,
                'message' => $result->message,
            ]);
        }

        return $result;
    }

    private function isClientError(GrpcStatus $status): bool
    {
        return match ($status) {
            GrpcStatus::InvalidArgument,
            GrpcStatus::NotFound,
            GrpcStatus::AlreadyExists,
            GrpcStatus::PermissionDenied,
            GrpcStatus::FailedPrecondition,
            GrpcStatus::OutOfRange,
            GrpcStatus::Unauthenticated,
            GrpcStatus::ResourceExhausted,
            GrpcStatus::Cancelled => true,
            default => false,
        };
    }
}
