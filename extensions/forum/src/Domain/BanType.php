<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Domain;

use Pulsar\Api\Api;

/**
 * Type of forum user ban.
 */
#[Api(since: '1.0.0')]
enum BanType: string
{
    case Temporary = 'temporary';
    case Permanent = 'permanent';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Temporary => 'Temporary',
            self::Permanent => 'Permanent',
        };
    }
}
