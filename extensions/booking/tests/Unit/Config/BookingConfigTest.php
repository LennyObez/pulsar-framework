<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Domain\BookingConfig;

#[CoversClass(BookingConfig::class)]
final class BookingConfigTest extends TestCase
{
    public function testFromArrayWithDefaults(): void
    {
        $config = BookingConfig::fromArray([]);

        self::assertFalse($config->depositRequired);
        self::assertSame(20, $config->depositPercent);
        self::assertSame(24, $config->minAdvanceHours);
        self::assertSame(90, $config->maxAdvanceDays);
        self::assertSame(24, $config->reminderHoursBefore);
        self::assertFalse($config->smsReminderEnabled);
        self::assertTrue($config->emailReminderEnabled);
        self::assertFalse($config->googleCalendarEnabled);
        self::assertSame('', $config->googleCalendarId);
        self::assertSame(24, $config->cancellationPolicyHours);
        self::assertSame('twilio', $config->smsProvider);
        self::assertSame('', $config->twilioSid);
        self::assertSame('', $config->twilioAuthToken);
    }

    public function testFromArrayWithCustomValues(): void
    {
        $config = BookingConfig::fromArray([
            'deposit_required' => true,
            'deposit_percent' => 50,
            'min_advance_hours' => 48,
            'max_advance_days' => 30,
            'reminder_hours_before' => 12,
            'sms_reminder_enabled' => true,
            'email_reminder_enabled' => false,
            'google_calendar_enabled' => true,
            'google_calendar_id' => 'primary',
            'cancellation_policy_hours' => 48,
            'sms_provider' => 'vonage',
            'twilio_sid' => 'AC123',
            'twilio_auth_token' => 'token123',
            'twilio_from_number' => '+1555000',
            'vonage_api_key' => 'vkey',
            'vonage_api_secret' => 'vsecret',
            'vonage_from_number' => '+1555001',
            'google_service_account_key_path' => '/path/to/key.json',
        ]);

        self::assertTrue($config->depositRequired);
        self::assertSame(50, $config->depositPercent);
        self::assertSame(48, $config->minAdvanceHours);
        self::assertSame(30, $config->maxAdvanceDays);
        self::assertSame(12, $config->reminderHoursBefore);
        self::assertTrue($config->smsReminderEnabled);
        self::assertFalse($config->emailReminderEnabled);
        self::assertTrue($config->googleCalendarEnabled);
        self::assertSame('primary', $config->googleCalendarId);
        self::assertSame(48, $config->cancellationPolicyHours);
        self::assertSame('vonage', $config->smsProvider);
        self::assertSame('AC123', $config->twilioSid);
        self::assertSame('token123', $config->twilioAuthToken);
        self::assertSame('+1555000', $config->twilioFromNumber);
        self::assertSame('vkey', $config->vonageApiKey);
        self::assertSame('vsecret', $config->vonageApiSecret);
        self::assertSame('+1555001', $config->vonageFromNumber);
        self::assertSame('/path/to/key.json', $config->googleServiceAccountKeyPath);
    }

    public function testFromArrayHandlesNumericStrings(): void
    {
        $config = BookingConfig::fromArray([
            'deposit_percent' => '30',
            'min_advance_hours' => '12',
        ]);

        self::assertSame(30, $config->depositPercent);
        self::assertSame(12, $config->minAdvanceHours);
    }

    public function testFromArrayIgnoresInvalidTypes(): void
    {
        $config = BookingConfig::fromArray([
            'deposit_percent' => [],
            'min_advance_hours' => null,
            'sms_provider' => 42,
        ]);

        self::assertSame(20, $config->depositPercent);
        self::assertSame(24, $config->minAdvanceHours);
        self::assertSame('twilio', $config->smsProvider);
    }
}
