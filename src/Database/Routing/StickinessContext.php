<?php

declare(strict_types=1);

namespace Pulsar\Database\Routing;

use Pulsar\Api\Api;

use function hrtime;

/**
 * Request-scoped context for write-then-read primary stickiness.
 *
 * Tracks when the last write occurred and whether the connection
 * should remain pinned to the primary. Supports both request-scoped
 * (until explicit reset) and timed stickiness (auto-expires).
 */
#[Api(since: '1.0.0')]
final class StickinessContext
{
    private bool $writeOccurred = false;
    private ?int $pinExpiresAtNs = null;
    private bool $requestScoped = false;

    /**
     * Record that a write operation occurred, activating stickiness.
     *
     * @param string|int $duration 'request' for request-scoped, or milliseconds
     */
    public function markWrite(string|int $duration = 'request'): void
    {
        $this->writeOccurred = true;

        if ($duration === 'request') {
            $this->requestScoped = true;
            $this->pinExpiresAtNs = null;
        } else {
            $this->requestScoped = false;
            $durationMs = (int) $duration;
            $this->pinExpiresAtNs = hrtime(true) + ($durationMs * 1_000_000);
        }
    }

    /**
     * Check if the connection should use the primary.
     */
    public function shouldUsePrimary(): bool
    {
        if (!$this->writeOccurred) {
            return false;
        }

        if ($this->requestScoped) {
            return true;
        }

        if ($this->pinExpiresAtNs !== null && hrtime(true) < $this->pinExpiresAtNs) {
            return true;
        }

        if ($this->pinExpiresAtNs !== null && hrtime(true) >= $this->pinExpiresAtNs) {
            $this->writeOccurred = false;
            $this->pinExpiresAtNs = null;

            return false;
        }

        return false;
    }

    /**
     * Reset all stickiness state for a new request cycle.
     */
    public function reset(): void
    {
        $this->writeOccurred = false;
        $this->pinExpiresAtNs = null;
        $this->requestScoped = false;
    }
}
