<?php

declare(strict_types=1);

namespace Pulsar\Broadcasting;

use Pulsar\Api\Api;

/**
 * Public broadcast channel: anyone can subscribe.
 * @api
 */
#[Api(since: '1.0.0')]
readonly class Channel
{
    public function __construct(
        public string $name,
    ) {}

    /**
     * Whether this channel requires authentication.
     */
    public function requiresAuth(): bool
    {
        return false;
    }

    /**
     * Get the wire-format channel name.
     */
    public function channelName(): string
    {
        return $this->name;
    }
}
