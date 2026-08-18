<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Domain;

use NoDiscard;
use Pulsar\Api\Api;

use function is_int;
use function is_numeric;
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
        public bool $routesEnabled = true,
        public string $routePrefix = '/booking',
        public string $adminRoutePrefix = '/admin/booking',
    ) {}

    /**
     * Build from the raw booking config array.
     *
     * Every value is `mixed` because the array reaches here from PHP config files, YAML
     * and environment variables. The keys are the supported surface; the types are
     * whatever the deployment supplied, and each is narrowed on the way in.
     *
     * @param array{
     *     deposit_required?: mixed,
     *     deposit_percent?: mixed,
     *     min_advance_hours?: mixed,
     *     max_advance_days?: mixed,
     *     reminder_hours_before?: mixed,
     *     sms_reminder_enabled?: mixed,
     *     email_reminder_enabled?: mixed,
     *     google_calendar_enabled?: mixed,
     *     google_calendar_id?: mixed,
     *     cancellation_policy_hours?: mixed,
     *     sms_provider?: mixed,
     *     twilio_sid?: mixed,
     *     twilio_auth_token?: mixed,
     *     twilio_from_number?: mixed,
     *     vonage_api_key?: mixed,
     *     vonage_api_secret?: mixed,
     *     vonage_from_number?: mixed,
     *     google_service_account_key_path?: mixed,
     *     routes_enabled?: mixed,
     *     route_prefix?: mixed,
     *     admin_route_prefix?: mixed,
     * } $data Raw array from config/booking.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            depositRequired: (bool) ($data['deposit_required'] ?? false),
            depositPercent: self::int($data['deposit_percent'] ?? null, 20),
            minAdvanceHours: self::int($data['min_advance_hours'] ?? null, 24),
            maxAdvanceDays: self::int($data['max_advance_days'] ?? null, 90),
            reminderHoursBefore: self::int($data['reminder_hours_before'] ?? null, 24),
            smsReminderEnabled: (bool) ($data['sms_reminder_enabled'] ?? false),
            emailReminderEnabled: (bool) ($data['email_reminder_enabled'] ?? true),
            googleCalendarEnabled: (bool) ($data['google_calendar_enabled'] ?? false),
            googleCalendarId: self::string($data['google_calendar_id'] ?? null, ''),
            cancellationPolicyHours: self::int($data['cancellation_policy_hours'] ?? null, 24),
            smsProvider: self::string($data['sms_provider'] ?? null, 'twilio'),
            twilioSid: self::string($data['twilio_sid'] ?? null, ''),
            twilioAuthToken: self::string($data['twilio_auth_token'] ?? null, ''),
            twilioFromNumber: self::string($data['twilio_from_number'] ?? null, ''),
            vonageApiKey: self::string($data['vonage_api_key'] ?? null, ''),
            vonageApiSecret: self::string($data['vonage_api_secret'] ?? null, ''),
            vonageFromNumber: self::string($data['vonage_from_number'] ?? null, ''),
            googleServiceAccountKeyPath: self::string($data['google_service_account_key_path'] ?? null, ''),
            routesEnabled: (bool) ($data['routes_enabled'] ?? true),
            routePrefix: self::string($data['route_prefix'] ?? null, '/booking'),
            adminRoutePrefix: self::string($data['admin_route_prefix'] ?? null, '/admin/booking'),
        );
    }

    /**
     * Environment variables are always strings, so '30' must mean 30. Anything that is
     * not a number at all falls back to the documented default rather than to 0, which
     * would silently disable a deposit or a cancellation window.
     */
    private static function int(mixed $value, int $default): int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && is_numeric($value) => (int) $value,
            default => $default,
        };
    }

    private static function string(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }
}
