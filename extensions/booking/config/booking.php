<?php

declare(strict_types=1);

return [
    'deposit_required' => false,
    'deposit_percent' => 20,
    'min_advance_hours' => 24,
    'max_advance_days' => 90,
    'reminder_hours_before' => 24,
    'sms_reminder_enabled' => false,
    'email_reminder_enabled' => true,
    'google_calendar_enabled' => false,
    'google_calendar_id' => '',
    'cancellation_policy_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | SMS Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Supported providers: 'twilio', 'vonage'
    |
    */
    'sms_provider' => 'twilio',
    'twilio_sid' => '',
    'twilio_auth_token' => '',
    'twilio_from_number' => '',
    'vonage_api_key' => '',
    'vonage_api_secret' => '',
    'vonage_from_number' => '',

    /*
    |--------------------------------------------------------------------------
    | Google Calendar Integration
    |--------------------------------------------------------------------------
    |
    | Path to the Google service account JSON key file.
    | Required when google_calendar_enabled is true.
    |
    */
    'google_service_account_key_path' => '',
];
