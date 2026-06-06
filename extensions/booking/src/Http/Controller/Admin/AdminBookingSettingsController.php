<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Http\Controller\Admin;

use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Domain\BookingConfig;
use Pulsar\Http\Message\Response;

/**
 * Admin controller for booking configuration management.
 */
#[Internal]
final readonly class AdminBookingSettingsController
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private BookingConfig $config,
    ) {}

    /**
     * GET /admin/booking/settings: show current config.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function show(): Response
    {
        return Response::json([
            'deposit_required' => $this->config->depositRequired,
            'deposit_percent' => $this->config->depositPercent,
            'min_advance_hours' => $this->config->minAdvanceHours,
            'max_advance_days' => $this->config->maxAdvanceDays,
            'reminder_hours_before' => $this->config->reminderHoursBefore,
            'sms_reminder_enabled' => $this->config->smsReminderEnabled,
            'email_reminder_enabled' => $this->config->emailReminderEnabled,
            'google_calendar_enabled' => $this->config->googleCalendarEnabled,
            'google_calendar_id' => $this->config->googleCalendarId,
            'cancellation_policy_hours' => $this->config->cancellationPolicyHours,
            'sms_provider' => $this->config->smsProvider,
        ]);
    }
}
