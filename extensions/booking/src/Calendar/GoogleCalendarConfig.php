<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Calendar;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Google Calendar integration configuration.
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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            calendarId: is_string($data['calendar_id'] ?? null) ? $data['calendar_id'] : '',
            serviceAccountKeyPath: is_string($data['service_account_key_path'] ?? null) ? $data['service_account_key_path'] : '',
            enabled: (bool) ($data['enabled'] ?? false),
        );
    }
}
