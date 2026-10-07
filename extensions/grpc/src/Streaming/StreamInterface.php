<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Streaming;

use Pulsar\Api\Api;

/**
 * Base contract for gRPC streaming operations.
 *
 * Streaming requires a persistent runtime (RoadRunner/FrankenPHP).
 * @api
 */
#[Api(since: '1.0.0')]
interface StreamInterface
{
    /**
     * Read the next message from the stream.
     *
     * @return string|null Serialized protobuf message, or null when the stream ends
     */
    public function read(): ?string;

    /**
     * Write a message to the stream.
     *
     * @param string $payload Serialized protobuf message
     */
    public function write(string $payload): void;

    /**
     * Close the stream for writing. No more messages may be sent.
     */
    public function close(): void;

    /**
     * Whether the stream has been closed.
     */
    public function isClosed(): bool;
}
