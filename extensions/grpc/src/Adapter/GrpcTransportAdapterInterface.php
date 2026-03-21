<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Adapter;

use Pulsar\Api\Api;

/**
 * Contract for gRPC transport adapters.
 *
 * Bridges the Pulsar gRPC server to an actual gRPC transport implementation
 * (grpc PECL extension, RoadRunner gRPC plugin, etc.). Pulsar owns the
 * service contracts and interceptor pipeline; the adapter owns the wire protocol.
 * @api
 */
#[Api(since: '1.0.0')]
interface GrpcTransportAdapterInterface
{
    /**
     * Start listening for gRPC connections.
     *
     * This is a blocking call that runs the transport event loop.
     * The callback receives raw gRPC frames and returns serialized responses.
     *
     * @param string $host Bind address (e.g. "0.0.0.0")
     * @param int $port    Bind port (e.g. 50051)
     * @param GrpcRequestHandler $handler  Handler for incoming requests
     */
    public function listen(string $host, int $port, GrpcRequestHandler $handler): void;

    /**
     * Signal the transport to stop accepting new connections and drain.
     */
    public function shutdown(): void;

    /**
     * The name of this adapter (e.g. "grpc_extension", "roadrunner").
     */
    public function name(): string;

    /**
     * Whether the underlying transport extension/library is available.
     */
    public function isAvailable(): bool;
}
