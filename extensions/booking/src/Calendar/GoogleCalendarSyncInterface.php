<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Calendar;

use Pulsar\Api\Api;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Exception\BookingException;

/**
 * Contract for Google Calendar synchronization.
 * @api
 */
#[Api(since: '1.0.0')]
interface GoogleCalendarSyncInterface
{
    /**
     * Create a calendar event for an appointment.
     *
     * @return string The Google Calendar event ID
     *
     * @throws BookingException If sync fails
     */
    public function createEvent(Appointment $appointment): string;

    /**
     * Update an existing calendar event.
     *
     * @throws BookingException If sync fails
     */
    public function updateEvent(string $eventId, Appointment $appointment): void;

    /**
     * Delete a calendar event.
     *
     * @throws BookingException If sync fails
     */
    public function deleteEvent(string $eventId): void;
}
