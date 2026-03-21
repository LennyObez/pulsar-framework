<?php

declare(strict_types=1);

namespace Pulsar\Notification\Consent;

use Pulsar\Api\Api;

/**
 * Snapshot of a notifiable entity's channel preferences and consent history.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class UserPreferences
{
    /**
     * @param string               $notifiableId       Unique identifier of the notifiable
     * @param array<string, bool>  $channelPreferences Map of channel name => opted-in status
     * @param list<ConsentRecord>  $consentRecords     Historical consent records
     */
    public function __construct(
        public string $notifiableId,
        public array $channelPreferences = [],
        public array $consentRecords = [],
    ) {}

    /**
     * Check if the notifiable has opted in to a specific channel.
     */
    public function isOptedIn(string $channel): bool
    {
        return $this->channelPreferences[$channel] ?? false;
    }

    /**
     * Check if the notifiable has explicitly opted out of a specific channel.
     *
     * Returns false if no preference is recorded (not the same as opted-in).
     */
    public function isOptedOut(string $channel): bool
    {
        if (!isset($this->channelPreferences[$channel])) {
            return false;
        }

        return !$this->channelPreferences[$channel];
    }
}
