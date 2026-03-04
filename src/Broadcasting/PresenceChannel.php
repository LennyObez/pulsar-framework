<?php

declare(strict_types=1);

namespace Pulsar\Broadcasting;

use Override;
use Pulsar\Api\Api;

/**
 * Presence channel: requires authentication and tracks online members.
 */
#[Api(since: '1.0.0')]
readonly class PresenceChannel extends Channel
{
    #[Override]
    public function requiresAuth(): bool
    {
        return true;
    }

    #[Override]
    public function channelName(): string
    {
        return 'presence-' . $this->name;
    }
}
