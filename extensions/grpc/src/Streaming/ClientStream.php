<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Streaming;

use LogicException;
use Pulsar\Api\Api;
use SplQueue;

/**
 * Client-side streaming implementation.
 *
 * The client sends a stream of messages, and the server responds with a single response
 * after the client has finished sending.
 */
#[Api(since: '1.0.0')]
final class ClientStream implements StreamInterface
{
    /** @var SplQueue<string> */
    private SplQueue $buffer;

    private bool $closed = false;

    private int $messageCount = 0;

    public function __construct()
    {
        /** @var SplQueue<string> $buffer */
        $buffer = new SplQueue();
        $this->buffer = $buffer;
    }

    /**
     * Read the next message from the client stream.
     *
     * @return string|null The next message, or null if no more messages are available
     */
    public function read(): ?string
    {
        if ($this->buffer->isEmpty()) {
            return null;
        }

        return $this->buffer->dequeue();
    }

    /**
     * Buffer a client message for reading.
     *
     * @throws LogicException If the stream has been closed
     */
    public function write(string $payload): void
    {
        if ($this->closed) {
            throw new LogicException('Cannot write to a closed stream.');
        }

        $this->buffer->enqueue($payload);
        $this->messageCount++;
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
     * Total number of messages received from the client.
     */
    public function messageCount(): int
    {
        return $this->messageCount;
    }

    /**
     * Number of messages currently buffered and not yet read.
     */
    public function pendingCount(): int
    {
        return $this->buffer->count();
    }
}
