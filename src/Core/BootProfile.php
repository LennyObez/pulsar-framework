<?php

declare(strict_types=1);

namespace Pulsar\Core;

use Pulsar\Api\Internal;

/**
 * Immutable boot timing profile.
 *
 * Captures hrtime-based microsecond durations for each boot phase.
 * Always-on during boot (6 hrtime calls, <100ns overhead).
 */
#[Internal]
final readonly class BootProfile
{
    /**
     * @param int $totalUs Total boot duration in microseconds
     * @param int $cacheLoadUs Cache load phase duration in microseconds
     * @param int $configUs Config + service registration phase duration in microseconds
     * @param int $extensionRegisterUs Extension register phase duration in microseconds
     * @param int $extensionBootUs Extension boot phase duration in microseconds
     * @param bool $cacheHit Whether config was loaded from cache
     * @param bool $routesCached Whether routes were loaded from cache
     */
    public function __construct(
        public int $totalUs,
        public int $cacheLoadUs,
        public int $configUs,
        public int $extensionRegisterUs,
        public int $extensionBootUs,
        public bool $cacheHit,
        public bool $routesCached,
    ) {}

    /**
     * @return array{total_us: int, cache_load_us: int, config_us: int, extension_register_us: int, extension_boot_us: int, cache_hit: bool, routes_cached: bool}
     */
    public function toArray(): array
    {
        return [
            'total_us' => $this->totalUs,
            'cache_load_us' => $this->cacheLoadUs,
            'config_us' => $this->configUs,
            'extension_register_us' => $this->extensionRegisterUs,
            'extension_boot_us' => $this->extensionBootUs,
            'cache_hit' => $this->cacheHit,
            'routes_cached' => $this->routesCached,
        ];
    }
}
