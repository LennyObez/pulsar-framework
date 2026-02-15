<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Claim;

use Pulsar\Api\Api;

/**
 * Origin of a zero-trust claim.
 *
 * Each signal provider produces claims tagged with the source that generated them.
 * This allows policy rules to require claims from specific, trusted sources and
 * enables audit trails to track which signals contributed to a decision.
 */
#[Api(since: '1.0.0')]
enum ClaimSource: string
{
    case DeviceSignal = 'device_signal';
    case LocationSignal = 'location_signal';
    case TimeSignal = 'time_signal';
    case BehaviorSignal = 'behavior_signal';
    case NetworkSignal = 'network_signal';
}
