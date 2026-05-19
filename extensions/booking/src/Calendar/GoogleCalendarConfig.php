<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Calendar;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Google Calendar integration configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class GoogleCalendarConfig
{
    public function __construct(
        public string $calendarId,
        public string $serviceAccountKeyPath,
        public bool $enabled,
    ) {}

    /**
     * @param array{
     *     calendar_id?: string,
     *     service_account_key_path?: string,
     *     enabled?: bool|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            calendarId: $data['calendar_id'] ?? '',
            serviceAccountKeyPath: $data['service_account_key_path'] ?? '',
            enabled: (bool) ($data['enabled'] ?? false),
        );
    }
}
