<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Streaming;

use InvalidArgumentException;
use Pulsar\Api\Internal;

/**
 * Flow control for streaming RPCs.
 *
 * Tracks the number of in-flight messages and enforces configurable
 * high/low watermarks to prevent buffer overflows.
 */
#[Internal(reason: 'Streaming flow control')]
final class BackpressureController
{
    private int $inFlight = 0;

    /**
     * @param int $highWatermark Maximum in-flight messages before blocking sends
     * @param int $lowWatermark  Resume threshold after in-flight drops below this level
     */
    public function __construct(
        private readonly int $highWatermark = 64,
        private readonly int $lowWatermark = 16,
    ) {
        if ($highWatermark < 1) {
            throw new InvalidArgumentException('High watermark must be at least 1.');
        }

        if ($lowWatermark < 0) {
            throw new InvalidArgumentException('Low watermark must be non-negative.');
        }

        if ($lowWatermark >= $highWatermark) {
            throw new InvalidArgumentException('Low watermark must be less than high watermark.');
        }
    }

    /**
     * Whether the sender is allowed to emit another message.
     *
     * Returns false when in-flight count reaches the high watermark,
     * and remains false until it drops to the low watermark.
     */
    public function canSend(): bool
    {
        return $this->inFlight < $this->highWatermark;
    }

    /**
     * Track that a message was sent (increases in-flight count).
     */
    public function onMessageSent(): void
    {
        $this->inFlight++;
    }

    /**
     * Track that a message was acknowledged/received (decreases in-flight count).
     */
    public function onMessageReceived(): void
    {
        if ($this->inFlight > 0) {
            $this->inFlight--;
        }
    }

    /**
     * Current number of in-flight messages.
     */
    public function inFlightCount(): int
    {
        return $this->inFlight;
    }

    /**
     * Whether the sender has resumed after being paused.
     *
     * True when in-flight count has dropped to or below the low watermark
     * after previously reaching the high watermark.
     */
    public function isResumed(): bool
    {
        return $this->inFlight <= $this->lowWatermark;
    }

    public function highWatermark(): int
    {
        return $this->highWatermark;
    }

    public function lowWatermark(): int
    {
        return $this->lowWatermark;
    }
}
