<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal;

use Pulsar\Api\Api;

/**
 * Port for network intelligence lookups.
 *
 * Provides information about IP addresses: proxy detection, Tor exit node checks,
 * and network zone classification. Implementations may use threat intelligence feeds,
 * local databases, or external APIs.
 */
#[Api(since: '1.0.0')]
interface NetworkIntelligenceInterface
{
    /**
     * Check whether the IP is a known proxy (open proxy, VPN endpoint, etc.).
     */
    public function isKnownProxy(string $ip): bool;

    /**
     * Check whether the IP is a known Tor exit node.
     */
    public function isTorExitNode(string $ip): bool;

    /**
     * Classify the IP into a network zone.
     *
     * @return string Zone identifier (e.g., "internal", "external", "vpn")
     */
    public function resolveZone(string $ip): string;
}
