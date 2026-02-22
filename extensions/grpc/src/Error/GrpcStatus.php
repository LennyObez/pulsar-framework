<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Error;

use Pulsar\Api\Api;

/**
 * Standard gRPC status codes per the gRPC specification.
 *
 * @see https://grpc.github.io/grpc/core/md_doc_statuscodes.html
 */
#[Api(since: '1.0.0')]
enum GrpcStatus: int
{
    case Ok = 0;
    case Cancelled = 1;
    case Unknown = 2;
    case InvalidArgument = 3;
    case DeadlineExceeded = 4;
    case NotFound = 5;
    case AlreadyExists = 6;
    case PermissionDenied = 7;
    case ResourceExhausted = 8;
    case FailedPrecondition = 9;
    case Aborted = 10;
    case OutOfRange = 11;
    case Unimplemented = 12;
    case Internal = 13;
    case Unavailable = 14;
    case DataLoss = 15;
    case Unauthenticated = 16;

    /**
     * Whether this status represents a successful response.
     */
    public function isOk(): bool
    {
        return $this === self::Ok;
    }

    /**
     * Whether this status indicates a retryable failure.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::DeadlineExceeded,
            self::ResourceExhausted,
            self::Unavailable,
            self::Aborted => true,
            default => false,
        };
    }

    /**
     * HTTP status code equivalent for gRPC-Web bridging.
     */
    public function httpStatusCode(): int
    {
        return match ($this) {
            self::Ok => 200,
            self::Cancelled => 499,
            self::InvalidArgument,
            self::FailedPrecondition,
            self::OutOfRange => 400,
            self::DeadlineExceeded => 504,
            self::NotFound => 404,
            self::AlreadyExists, self::Aborted => 409,
            self::PermissionDenied => 403,
            self::ResourceExhausted => 429,
            self::Unimplemented => 501,
            self::Unavailable => 503,
            self::Unauthenticated => 401,
            self::Unknown, self::Internal, self::DataLoss => 500,
        };
    }
}
