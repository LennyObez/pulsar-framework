<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use function in_array;

use Pulsar\Api\Internal;

/**
 * Deterministic registry of services that require per-request reset or eviction.
 *
 * Populated during kernel boot. The RequestSandbox iterates this registry
 * (not all container instances) for predictable, ordered cleanup.
 */
#[Internal]
final class RequestResetRegistry
{
    /** @var list<string> Service IDs implementing ResettableInterface */
    public private(set) array $resettableIds = [];

    /** @var list<string> Service IDs to evict (forgetInstance) between requests */
    public private(set) array $evictableIds = [];

    /**
     * Register a service ID as resettable (implements ResettableInterface).
     */
    public function registerResettable(string $serviceId): void
    {
        if (!in_array($serviceId, $this->resettableIds, true)) {
            $this->resettableIds[] = $serviceId;
        }
    }

    /**
     * Register a service ID for eviction between requests.
     */
    public function registerEvictable(string $serviceId): void
    {
        if (!in_array($serviceId, $this->evictableIds, true)) {
            $this->evictableIds[] = $serviceId;
        }
    }
}
