<?php

declare(strict_types=1);

namespace Pulsar\Database\Routing;

use Fiber;
use Pulsar\Api\Api;
use stdClass;
use WeakMap;

use function hrtime;

/**
 * Request-scoped context for write-then-read primary stickiness, isolated
 * per Fiber.
 *
 * Tracks when the last write occurred and whether the connection should
 * remain pinned to the primary. Supports both request-scoped (until
 * explicit reset) and timed stickiness (auto-expires).
 *
 * Persistent-runtime workers (RoadRunner, FrankenPHP, Swoole) interleave
 * Fiber-suspended HTTP requests on the same worker process. A shared
 * field would let request A's write pin request B's reads to the
 * primary (or, worse, let request B's expiry release request A's pin
 * prematurely), producing non-deterministic read-your-write semantics
 * across tenants. State is therefore keyed by `Fiber::getCurrent()` via
 * a `WeakMap`, with a stable `$rootKey` for non-Fiber callers.
 * @api
 */
#[Api(since: '1.0.0')]
final class StickinessContext
{
    /**
     * @var WeakMap<object, array{writeOccurred: bool, pinExpiresAtNs: ?int, requestScoped: bool}>
     */
    private WeakMap $slots;

    private readonly stdClass $rootKey;

    public function __construct()
    {
        /** @var WeakMap<object, array{writeOccurred: bool, pinExpiresAtNs: ?int, requestScoped: bool}> $map */
        $map = new WeakMap();
        $this->slots = $map;
        $this->rootKey = new stdClass();
    }

    /**
     * Record that a write operation occurred, activating stickiness for
     * the current Fiber only.
     *
     * @param string|int $duration 'request' for request-scoped, or milliseconds
     */
    public function markWrite(string|int $duration = 'request'): void
    {
        $key = $this->currentKey();

        if ($duration === 'request') {
            $this->slots[$key] = [
                'writeOccurred' => true,
                'pinExpiresAtNs' => null,
                'requestScoped' => true,
            ];

            return;
        }

        $durationMs = (int) $duration;

        $this->slots[$key] = [
            'writeOccurred' => true,
            'pinExpiresAtNs' => (int) hrtime(true) + ($durationMs * 1_000_000),
            'requestScoped' => false,
        ];
    }

    /**
     * Check if the connection should use the primary for the current Fiber.
     */
    public function shouldUsePrimary(): bool
    {
        $key = $this->currentKey();
        $slot = $this->slots[$key] ?? null;

        if ($slot === null || !$slot['writeOccurred']) {
            return false;
        }

        if ($slot['requestScoped']) {
            return true;
        }

        $expiry = $slot['pinExpiresAtNs'];

        if ($expiry !== null && hrtime(true) < $expiry) {
            return true;
        }

        if ($expiry !== null && hrtime(true) >= $expiry) {
            // Expiry passed — clear the slot so subsequent reads return false fast.
            unset($this->slots[$key]);

            return false;
        }

        return false;
    }

    /**
     * Reset stickiness state for the current Fiber's request cycle.
     */
    public function reset(): void
    {
        unset($this->slots[$this->currentKey()]);
    }

    private function currentKey(): object
    {
        return Fiber::getCurrent() ?? $this->rootKey;
    }
}
