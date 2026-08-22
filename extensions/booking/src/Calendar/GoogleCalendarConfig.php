<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Calendar;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

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
     *     calendar_id?: mixed,
     *     service_account_key_path?: mixed,
     *     enabled?: mixed,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            calendarId: isset($data['calendar_id']) && is_string($data['calendar_id']) ? $data['calendar_id'] : '',
            serviceAccountKeyPath: isset($data['service_account_key_path']) && is_string($data['service_account_key_path'])
                ? $data['service_account_key_path']
                : '',
            enabled: (bool) ($data['enabled'] ?? false),
        );
    }
}
