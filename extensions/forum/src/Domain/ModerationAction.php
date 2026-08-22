<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Domain;

use Pulsar\Api\Api;

/**
 * Actions a moderator can take on forum content or users.
 * @api
 */
#[Api(since: '1.0.0')]
enum ModerationAction: string
{
    case Approve = 'approve';
    case Hide = 'hide';
    case Delete = 'delete';
    case Warn = 'warn';
    case Ban = 'ban';
    case Unban = 'unban';
    case Edit = 'edit';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Approve',
            self::Hide => 'Hide',
            self::Delete => 'Delete',
            self::Warn => 'Warn',
            self::Ban => 'Ban',
            self::Unban => 'Unban',
            self::Edit => 'Edit',
        };
    }
}
