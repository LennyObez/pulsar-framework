<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Interceptor;

use Pulsar\Api\Api;
use Pulsar\Extension\Grpc\Error\GrpcStatus;

/**
 * Result of an interceptor or handler invocation.
 *
 * Carries the serialized response payload, gRPC status, optional error
 * message, and trailing metadata.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class InterceptorResult
{
    /**
     * @param string $payload                        Serialized response payload
     * @param GrpcStatus $status                     gRPC status code
     * @param string $message                        Human-readable status message
     * @param array<string, list<string>> $trailers  Trailing metadata
     */
    public function __construct(
        public string $payload = '',
        public GrpcStatus $status = GrpcStatus::Ok,
        public string $message = '',
        public array $trailers = [],
    ) {}

    /**
     * Create a successful result with the given response payload.
     *
     * @param array<string, list<string>> $trailers
     */
    public static function ok(string $payload, array $trailers = []): self
    {
        return new self(payload: $payload, trailers: $trailers);
    }

    /**
     * Create an error result.
     *
     * @param array<string, list<string>> $trailers
     */
    public static function error(GrpcStatus $status, string $message = '', array $trailers = []): self
    {
        return new self(status: $status, message: $message, trailers: $trailers);
    }

    /**
     * Whether this result represents a successful response.
     */
    public function isOk(): bool
    {
        return $this->status->isOk();
    }
}
