<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Domain;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_string;

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
     * @param array<string, mixed> $data Raw array from config/booking.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            depositRequired: (bool) ($data['deposit_required'] ?? false),
            depositPercent: self::int($data, 'deposit_percent', 20),
            minAdvanceHours: self::int($data, 'min_advance_hours', 24),
            maxAdvanceDays: self::int($data, 'max_advance_days', 90),
            reminderHoursBefore: self::int($data, 'reminder_hours_before', 24),
            smsReminderEnabled: (bool) ($data['sms_reminder_enabled'] ?? false),
            emailReminderEnabled: (bool) ($data['email_reminder_enabled'] ?? true),
            googleCalendarEnabled: (bool) ($data['google_calendar_enabled'] ?? false),
            googleCalendarId: self::string($data, 'google_calendar_id', ''),
            cancellationPolicyHours: self::int($data, 'cancellation_policy_hours', 24),
            smsProvider: self::string($data, 'sms_provider', 'twilio'),
            twilioSid: self::string($data, 'twilio_sid', ''),
            twilioAuthToken: self::string($data, 'twilio_auth_token', ''),
            twilioFromNumber: self::string($data, 'twilio_from_number', ''),
            vonageApiKey: self::string($data, 'vonage_api_key', ''),
            vonageApiSecret: self::string($data, 'vonage_api_secret', ''),
            vonageFromNumber: self::string($data, 'vonage_from_number', ''),
            googleServiceAccountKeyPath: self::string($data, 'google_service_account_key_path', ''),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function int(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
