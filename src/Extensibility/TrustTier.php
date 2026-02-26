<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;

/**
 * Trust tiers for extensions.
 *
 * Determines the effective capability set an extension receives
 * during bootstrap. The effective tier is resolved by host policy,
 * not the extension's own declaration.
 *
 * Tier ordering (highest to lowest): Core > Verified > Community > Untrusted.
 */
#[Api(since: '1.0.0')]
enum TrustTier: string
{
    case Core = 'core';
    case Verified = 'verified';
    case Community = 'community';
    case Untrusted = 'untrusted';

    /**
     * Check if this tier is at least as privileged as the given minimum.
     */
    public function atLeast(self $minimum): bool
    {
        return self::rank($this) >= self::rank($minimum);
    }

    private static function rank(self $tier): int
    {
        return match ($tier) {
            self::Core => 3,
            self::Verified => 2,
            self::Community => 1,
            self::Untrusted => 0,
        };
    }
}
