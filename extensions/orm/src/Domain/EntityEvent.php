<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use Pulsar\Api\Api;

/**
 * Lifecycle events dispatched during entity persistence operations.
 *
 * "creating"/"updating"/"deleting" fire before the database operation.
 * "created"/"updated"/"deleted" fire after the operation succeeds.
 *
 * Pre-events (creating/updating/deleting) can prevent the operation
 * by returning false from the observer.
 */
#[Api(since: '1.0.0')]
enum EntityEvent: string
{
    case Creating = 'creating';
    case Created = 'created';
    case Updating = 'updating';
    case Updated = 'updated';
    case Deleting = 'deleting';
    case Deleted = 'deleted';

    /**
     * Whether this event fires before the database operation.
     */
    public function isBefore(): bool
    {
        return match ($this) {
            self::Creating, self::Updating, self::Deleting => true,
            self::Created, self::Updated, self::Deleted => false,
        };
    }

    /**
     * Get the corresponding post-event for a pre-event.
     */
    public function postEvent(): self
    {
        return match ($this) {
            self::Creating => self::Created,
            self::Updating => self::Updated,
            self::Deleting => self::Deleted,
            default => $this,
        };
    }
}
