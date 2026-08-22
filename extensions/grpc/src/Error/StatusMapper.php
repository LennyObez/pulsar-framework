<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Error;

use InvalidArgumentException;
use LogicException;
use OverflowException;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

/**
 * Maps PHP exceptions to gRPC status codes.
 *
 * Provides a standard mapping from exception types to gRPC statuses,
 * with special handling for {@see GrpcException} which carries its own status.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StatusMapper
{
    /**
     * Map a throwable to a gRPC status code.
     *
     * Exception mapping:
     * - {@see GrpcException}: uses the exception's own status
     * - {@see InvalidArgumentException}: InvalidArgument
     * - {@see OverflowException}: ResourceExhausted
     * - {@see LogicException}: FailedPrecondition
     * - {@see RuntimeException}: Internal
     * - All others: Unknown
     */
    public static function fromException(Throwable $exception): GrpcStatus
    {
        if ($exception instanceof GrpcException) {
            return $exception->status;
        }

        // Order matters: OverflowException extends RuntimeException,
        // and InvalidArgumentException extends LogicException.
        // Check specific subtypes before their parents.
        return match (true) {
            $exception instanceof InvalidArgumentException => GrpcStatus::InvalidArgument,
            $exception instanceof OverflowException => GrpcStatus::ResourceExhausted,
            $exception instanceof LogicException => GrpcStatus::FailedPrecondition,
            $exception instanceof RuntimeException => GrpcStatus::Internal,
            default => GrpcStatus::Unknown,
        };
    }
}
