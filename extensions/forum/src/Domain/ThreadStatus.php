<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Domain;

use Pulsar\Api\Api;

/**
 * Thread lifecycle status with state-machine transitions.
 */
#[Api(since: '1.0.0')]
enum ThreadStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Locked = 'locked';

    /**
     * Whether a transition from this status to the target is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        return match ($this) {
            self::Open => match ($target) {
                self::Closed, self::Locked => true,
                default => false,
            },
            self::Closed => match ($target) {
                self::Open, self::Locked => true,
                default => false,
            },
            self::Locked => match ($target) {
                self::Open, self::Closed => true,
                default => false,
            },
        };
    }

    /**
     * Whether new replies can be posted to a thread in this status.
     */
    public function allowsReplies(): bool
    {
        return $this === self::Open;
    }

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Locked => 'Locked',
        };
    }
}
