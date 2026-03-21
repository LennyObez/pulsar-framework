<?php

declare(strict_types=1);

namespace Pulsar\Broadcasting;

use Pulsar\Api\Api;

/**
 * Optional interface for broadcast events that want to exclude the sender.
 *
 * When combined with #[ShouldBroadcast(toOthers: true)], the connection
 * returned by excludeConnectionId() will not receive the broadcast.
 * @api
 */
#[Api(since: '1.0.0')]
interface ExcludesConnectionInterface
{
    /**
     * Get the connection ID to exclude from the broadcast.
     */
    public function excludeConnectionId(): ?string;
}
