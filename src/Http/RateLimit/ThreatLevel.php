<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Global threat level affecting rate limit enforcement.
 * @api
 */
#[Api(since: '1.0.0')]
enum ThreatLevel: string
{
    /** Normal operations: standard limits apply. */
    case Normal = 'normal';

    /** Elevated threat: limits reduced to 75%. */
    case Elevated = 'elevated';

    /** High threat: limits reduced to 50%. */
    case High = 'high';

    /** Active attack: limits reduced to 25%. */
    case Critical = 'critical';

    /**
     * Get the limit multiplier for this threat level.
     */
    #[NoDiscard]
    public function limitMultiplier(): float
    {
        return match ($this) {
            self::Normal => 1.0,
            self::Elevated => 0.75,
            self::High => 0.50,
            self::Critical => 0.25,
        };
    }
}
