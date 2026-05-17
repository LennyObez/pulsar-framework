<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Signal;

use Pulsar\Api\Api;

/**
 * Port for resolving an IP address to a geographic location.
 *
 * Implementations may use MaxMind GeoIP, IP-API, or similar services.
 * Returns null when the IP cannot be resolved (e.g., localhost, private ranges).
 * @api
 */
#[Api(since: '1.0.0')]
interface GeoLocationResolverInterface
{
    public function resolve(string $ip): ?GeoLocation;
}
