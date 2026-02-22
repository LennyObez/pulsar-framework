<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Storage;

use Pulsar\Api\Api;

/**
 * Classification level for workflow context fields.
 *
 * Determines visibility and retention rules for individual fields
 * stored in a workflow instance context. Higher levels indicate
 * more sensitive data requiring stricter access controls.
 */
#[Api(since: '1.0.0')]
enum ClassificationLevel: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Restricted = 'restricted';
    case Pii = 'pii';

    /**
     * Check if this level is at or below the given maximum.
     */
    public function isAtOrBelow(self $maxLevel): bool
    {
        return self::ordinal($this) <= self::ordinal($maxLevel);
    }

    private static function ordinal(self $level): int
    {
        return match ($level) {
            self::Public => 0,
            self::Internal => 1,
            self::Restricted => 2,
            self::Pii => 3,
        };
    }
}
