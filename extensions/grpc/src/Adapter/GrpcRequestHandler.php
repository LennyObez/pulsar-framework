<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Adapter;

use Pulsar\Api\Api;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;

/**
 * Callback interface that transport adapters invoke for each incoming gRPC request.
 *
 * The adapter unpacks the wire-format request and delegates to this handler,
 * which runs the interceptor pipeline and service dispatch.
 */
#[Api(since: '1.0.0')]
interface GrpcRequestHandler
{
    /**
     * Handle an incoming gRPC request.
     *
     * @param string $fullMethodName  Fully qualified method (e.g. "/package.Service/Method")
     * @param string $payload         Serialized protobuf request bytes
     * @param array<string, list<string>> $metadata  Request metadata (headers)
     * @param string|null $peerIdentity  Client identity from mTLS (SAN), or null
     *
     * @return InterceptorResult The response to send back to the client
     */
    public function handle(
        string $fullMethodName,
        string $payload,
        array $metadata = [],
        ?string $peerIdentity = null,
    ): InterceptorResult;
}
