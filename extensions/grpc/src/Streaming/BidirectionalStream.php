<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Streaming;

use LogicException;
use Pulsar\Api\Api;
use SplQueue;

/**
 * Full-duplex bidirectional streaming implementation.
 *
 * Both client and server can read and write messages independently.
 * Uses separate read and write buffers for thread-safe operation.
 * @api
 */
#[Api(since: '1.0.0')]
final class BidirectionalStream implements StreamInterface
{
    /** @var SplQueue<string> */
    private SplQueue $readBuffer;

    /** @var SplQueue<string> */
    private SplQueue $writeBuffer;

    private bool $closed = false;

    public function __construct()
    {
        /** @var SplQueue<string> $readBuffer */
        $readBuffer = new SplQueue();
        $this->readBuffer = $readBuffer;
        /** @var SplQueue<string> $writeBuffer */
        $writeBuffer = new SplQueue();
        $this->writeBuffer = $writeBuffer;
    }

    /**
     * Read the next inbound message.
     *
     * @return string|null The next message, or null if the read buffer is empty
     */
    public function read(): ?string
    {
        if ($this->readBuffer->isEmpty()) {
            return null;
        }

        return $this->readBuffer->dequeue();
    }

    /**
     * Write an outbound message to the write buffer.
     *
     * @throws LogicException If the stream has been closed
     */
    public function write(string $payload): void
    {
        if ($this->closed) {
            throw new LogicException('Cannot write to a closed stream.');
        }

        $this->writeBuffer->enqueue($payload);
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Push an inbound message into the read buffer.
     *
     * Used by the transport layer to deliver messages from the remote peer.
     */
    public function pushInbound(string $payload): void
    {
        $this->readBuffer->enqueue($payload);
    }

    /**
     * Consume the next outbound message from the write buffer.
     *
     * Used by the transport layer to send messages to the remote peer.
     *
     * @return string|null The next outbound message, or null if the buffer is empty
     */
    public function pullOutbound(): ?string
    {
        if ($this->writeBuffer->isEmpty()) {
            return null;
        }

        return $this->writeBuffer->dequeue();
    }

    /**
     * Number of messages in the read buffer.
     */
    public function readBufferSize(): int
    {
        return $this->readBuffer->count();
    }

    /**
     * Number of messages in the write buffer.
     */
    public function writeBufferSize(): int
    {
        return $this->writeBuffer->count();
    }
}
