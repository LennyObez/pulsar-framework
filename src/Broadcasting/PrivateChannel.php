<?php

declare(strict_types=1);

namespace Pulsar\Broadcasting;

use Override;
use Pulsar\Api\Api;

/**
 * Private broadcast channel: requires authentication before subscribing.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PrivateChannel extends Channel
{
    #[Override]
    public function requiresAuth(): bool
    {
        return true;
    }

    #[Override]
    public function channelName(): string
    {
        return 'private-' . $this->name;
    }
}
