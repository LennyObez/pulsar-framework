<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use Pulsar\Api\Internal;

/**
 * Canonical ranking of MFA enforcement scopes, shared across the compliance module.
 *
 * Lower rank == broader (more restrictive) enforcement. A single source of truth
 * prevents the resolver and the regression detector from drifting apart: if a new
 * scope were added to one ranking table but not the other, the detector would
 * silently treat it as the least-restrictive 'none', a security regression.
 *
 * Unknown scopes resolve to the rank of 'none' (narrowest), which is the safe
 * conservative default for both production (the resolver never widens) and
 * detection (an unknown current scope is treated as the weakest).
 */
#[Internal(reason: 'Shared MFA scope ranking; depend on resolved profile values, not this helper')]
final class MfaScopeRank
{
    /** MFA scope ranking from broadest (0) to narrowest (3). */
    private const array RANKS = [
        'always' => 0,
        'privileged' => 1,
        'sensitive-data' => 2,
        'none' => 3,
    ];

    /**
     * Resolve the rank of a scope. Unknown scopes map to the narrowest ('none') rank.
     */
    public static function rank(string $scope): int
    {
        return self::RANKS[$scope] ?? self::RANKS['none'];
    }
}
