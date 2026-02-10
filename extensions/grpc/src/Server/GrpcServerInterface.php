<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Server;

use Pulsar\Api\Api;
use Pulsar\Extension\Grpc\Adapter\GrpcRequestHandler;

/**
 * Contract for the gRPC server.
 *
 * Decouples the server lifecycle from the concrete implementation,
 * allowing CLI commands and tests to depend on the interface.
 */
#[Api(since: '1.0.0')]
interface GrpcServerInterface extends GrpcRequestHandler
{
    /**
     * Start the gRPC server (blocking).
     */
    public function start(): void;

    /**
     * Stop the gRPC server.
     */
    public function stop(): void;
}
