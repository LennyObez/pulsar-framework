<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Http;

use Pulsar\Api\Internal;
use Socket;

use function strlen;

/**
 * Per-connection state for a persistent HTTP connection.
 *
 * Holds the read buffer, connection metadata, and keep-alive tracking.
 */
#[Internal]
final class ConnectionContext
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public string $readBuffer = '';
    public int $bytesRead = 0;
    public float $lastActivity;
    public int $keepAliveRemaining;

    /**
     * @var 'idle'|'headers'|'body'
     *
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public string $currentRequestPhase = 'idle';
    public float $phaseStartedAt;

    /**
     * @param Socket $socket The client socket
     */
    public function __construct(
        public readonly Socket $socket,
        int $maxKeepAliveRequests = 100,
    ) {
        $now = microtime(true);
        $this->lastActivity = $now;
        $this->phaseStartedAt = $now;
        $this->keepAliveRemaining = $maxKeepAliveRequests;
    }

    /**
     * Transition to a new request phase and reset the phase timer.
     *
     * @param 'idle'|'headers'|'body' $phase
     */
    public function enterPhase(string $phase): void
    {
        $this->currentRequestPhase = $phase;
        $this->phaseStartedAt = microtime(true);
        $this->lastActivity = $this->phaseStartedAt;
    }

    /**
     * Append data to the read buffer.
     */
    public function appendToBuffer(string $data): void
    {
        $this->readBuffer .= $data;
        $this->bytesRead += strlen($data);
        $this->lastActivity = microtime(true);
    }

    /**
     * Consume bytes from the front of the read buffer.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function consumeBuffer(int $length): string
    {
        $consumed = substr($this->readBuffer, 0, $length);
        $this->readBuffer = substr($this->readBuffer, $length);

        return $consumed;
    }

    /**
     * Decrement keep-alive counter. Returns true if more requests are allowed.
     */
    public function decrementKeepAlive(): bool
    {
        $this->keepAliveRemaining--;

        return $this->keepAliveRemaining > 0;
    }
}
