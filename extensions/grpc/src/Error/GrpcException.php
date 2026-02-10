<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Error;

use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

/**
 * Exception carrying a gRPC status code and optional details.
 *
 * Thrown from service handlers to signal gRPC errors. The interceptor
 * pipeline catches these and maps them to the appropriate gRPC response.
 */
#[Api(since: '1.0.0')]
final class GrpcException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details Rich error details (google.rpc.Status model)
     */
    public function __construct(
        public readonly GrpcStatus $status,
        string $message = '',
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $status->name, $status->value, $previous);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(GrpcStatus::NotFound, $message);
    }

    public static function invalidArgument(string $message = 'Invalid argument'): self
    {
        return new self(GrpcStatus::InvalidArgument, $message);
    }

    public static function unauthenticated(string $message = 'Unauthenticated'): self
    {
        return new self(GrpcStatus::Unauthenticated, $message);
    }

    public static function permissionDenied(string $message = 'Permission denied'): self
    {
        return new self(GrpcStatus::PermissionDenied, $message);
    }

    public static function internal(string $message = 'Internal error'): self
    {
        return new self(GrpcStatus::Internal, $message);
    }

    public static function unavailable(string $message = 'Service unavailable'): self
    {
        return new self(GrpcStatus::Unavailable, $message);
    }

    public static function unimplemented(string $message = 'Method not implemented'): self
    {
        return new self(GrpcStatus::Unimplemented, $message);
    }

    public static function deadlineExceeded(string $message = 'Deadline exceeded'): self
    {
        return new self(GrpcStatus::DeadlineExceeded, $message);
    }

    public static function resourceExhausted(string $message = 'Resource exhausted'): self
    {
        return new self(GrpcStatus::ResourceExhausted, $message);
    }
}
