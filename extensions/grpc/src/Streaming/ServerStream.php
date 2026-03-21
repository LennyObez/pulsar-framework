<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Streaming;

use LogicException;
use Pulsar\Api\Api;
use SplQueue;

/**
 * Server-side streaming implementation.
 *
 * The client sends a single request, and the server sends a stream of responses.
 * Messages are buffered internally until consumed or the stream is closed.
 * @api
 */
#[Api(since: '1.0.0')]
final class ServerStream implements StreamInterface
{
    /** @var SplQueue<string> */
    private SplQueue $buffer;

    private bool $closed = false;

    private ?string $clientRequest = null;

    public function __construct()
    {
        /** @var SplQueue<string> $buffer */
        $buffer = new SplQueue();
        $this->buffer = $buffer;
    }

    /**
     * Set the single client request for this server-streaming RPC.
     */
    public function setClientRequest(string $payload): void
    {
        $this->clientRequest = $payload;
    }

    /**
     * Read the client's single request message.
     *
     * For server-side streaming, the client sends one request.
     * Returns null after the request has been consumed.
     */
    public function read(): ?string
    {
        $request = $this->clientRequest;
        $this->clientRequest = null;

        return $request;
    }

    /**
     * Write a response message to the stream buffer.
     *
     * @throws LogicException If the stream has been closed
     */
    public function write(string $payload): void
    {
        if ($this->closed) {
            throw new LogicException('Cannot write to a closed stream.');
        }

        $this->buffer->enqueue($payload);
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
     * Consume the next buffered response message.
     *
     * @return string|null The next message, or null if the buffer is empty
     */
    public function receive(): ?string
    {
        if ($this->buffer->isEmpty()) {
            return null;
        }

        return $this->buffer->dequeue();
    }

    /**
     * Number of messages currently buffered.
     */
    public function bufferSize(): int
    {
        return $this->buffer->count();
    }
}
