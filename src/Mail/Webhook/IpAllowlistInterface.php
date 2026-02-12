<?php

declare(strict_types=1);

namespace Pulsar\Mail\Webhook;

use Pulsar\Api\Api;

/**
 * Verifies webhook source IPs against per-provider allowlists.
 */
#[Api(since: '1.0.0')]
interface IpAllowlistInterface
{
    /**
     * Check whether the given IP is allowed for the specified provider.
     */
    public function isAllowed(string $ip, string $provider): bool;
}
