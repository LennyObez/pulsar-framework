<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Domain;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Booking extension configuration DTO.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BookingConfig
{
    public function __construct(
        public bool $depositRequired,
        public int $depositPercent,
        public int $minAdvanceHours,
        public int $maxAdvanceDays,
        public int $reminderHoursBefore,
        public bool $smsReminderEnabled,
        public bool $emailReminderEnabled,
        public bool $googleCalendarEnabled,
        public string $googleCalendarId,
        public int $cancellationPolicyHours,
        public string $smsProvider,
        public string $twilioSid,
        public string $twilioAuthToken,
        public string $twilioFromNumber,
        public string $vonageApiKey,
        public string $vonageApiSecret,
        public string $vonageFromNumber,
        public string $googleServiceAccountKeyPath,
    ) {}

    /**
     * Build from the raw booking config array.
     *
     * @param array{
     *     deposit_required?: bool|int|string,
     *     deposit_percent?: int,
     *     min_advance_hours?: int,
     *     max_advance_days?: int,
     *     reminder_hours_before?: int,
     *     sms_reminder_enabled?: bool|int|string,
     *     email_reminder_enabled?: bool|int|string,
     *     google_calendar_enabled?: bool|int|string,
     *     google_calendar_id?: string,
     *     cancellation_policy_hours?: int,
     *     sms_provider?: string,
     *     twilio_sid?: string,
     *     twilio_auth_token?: string,
     *     twilio_from_number?: string,
     *     vonage_api_key?: string,
     *     vonage_api_secret?: string,
     *     vonage_from_number?: string,
     *     google_service_account_key_path?: string,
     * } $data Raw array from config/booking.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            depositRequired: (bool) ($data['deposit_required'] ?? false),
            depositPercent: $data['deposit_percent'] ?? 20,
            minAdvanceHours: $data['min_advance_hours'] ?? 24,
            maxAdvanceDays: $data['max_advance_days'] ?? 90,
            reminderHoursBefore: $data['reminder_hours_before'] ?? 24,
            smsReminderEnabled: (bool) ($data['sms_reminder_enabled'] ?? false),
            emailReminderEnabled: (bool) ($data['email_reminder_enabled'] ?? true),
            googleCalendarEnabled: (bool) ($data['google_calendar_enabled'] ?? false),
            googleCalendarId: $data['google_calendar_id'] ?? '',
            cancellationPolicyHours: $data['cancellation_policy_hours'] ?? 24,
            smsProvider: $data['sms_provider'] ?? 'twilio',
            twilioSid: $data['twilio_sid'] ?? '',
            twilioAuthToken: $data['twilio_auth_token'] ?? '',
            twilioFromNumber: $data['twilio_from_number'] ?? '',
            vonageApiKey: $data['vonage_api_key'] ?? '',
            vonageApiSecret: $data['vonage_api_secret'] ?? '',
            vonageFromNumber: $data['vonage_from_number'] ?? '',
            googleServiceAccountKeyPath: $data['google_service_account_key_path'] ?? '',
        );
    }
}
