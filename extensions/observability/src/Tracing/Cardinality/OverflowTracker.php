<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Cardinality;

use Pulsar\Api\Internal;

use function array_key_exists;
use function count;

/**
 * Bounded set for tracking unknown attribute keys.
 *
 * Tracks up to maxSize unique keys. Once at capacity, new keys are silently
 * ignored to prevent unbounded memory growth from high-cardinality attributes.
 */
#[Internal(reason: 'Implementation detail of AttributeAllowlist')]
final class OverflowTracker
{
    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(
        private readonly int $maxSize = 1000,
    ) {}

    /**
     * Track a key. Returns true if the key was newly added (first occurrence),
     * false if already known or at capacity.
     */
    public function track(string $key): bool
    {
        if (array_key_exists($key, $this->seen)) {
            return false;
        }

        if ($this->isFull()) {
            return false;
        }

        $this->seen[$key] = true;

        return true;
    }

    public function count(): int
    {
        return count($this->seen);
    }

    public function isFull(): bool
    {
        return count($this->seen) >= $this->maxSize;
    }
}
