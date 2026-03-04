<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Http\Controller;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Contracts\BookingServiceInterface;
use Pulsar\Extension\Booking\Contracts\TimeSlotManagerInterface;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Request;

/**
 * Front-office booking controller.
 *
 * Handles the multi-step booking form and appointment status checks.
 */
#[Internal]
final readonly class BookingController
{
    public function __construct(
        private BookingServiceInterface $bookingService,
        private AppointmentRepositoryInterface $repository,
        private TimeSlotManagerInterface $timeSlotManager,
    ) {}

    /**
     * GET /booking: show the booking form.
     */
    public function form(Request $request): Response
    {
        return Response::html('<h1>Book an Appointment</h1>');
    }

    /**
     * POST /booking: submit a new booking request.
     */
    public function submit(Request $request): Response
    {
        $serviceId = (string) ($request->post['service_id'] ?? '');
        $customerId = (string) ($request->post['customer_id'] ?? '');
        $customerName = (string) ($request->post['customer_name'] ?? '');
        $customerEmail = (string) ($request->post['customer_email'] ?? '');
        $customerPhone = (string) ($request->post['customer_phone'] ?? '');
        $scheduledAtRaw = (string) ($request->post['scheduled_at'] ?? '');
        $notes = (string) ($request->post['notes'] ?? '');

        if ($serviceId === '' || $customerName === '' || $customerEmail === '' || $scheduledAtRaw === '') {
            return Response::json([
                'error' => 'Missing required fields: service_id, customer_name, customer_email, scheduled_at',
            ], 400);
        }

        $scheduledAt = new DateTimeImmutable($scheduledAtRaw);

        try {
            $appointment = $this->bookingService->requestAppointment(
                serviceId: $serviceId,
                customerId: $customerId,
                customerName: $customerName,
                customerEmail: $customerEmail,
                customerPhone: $customerPhone,
                scheduledAt: $scheduledAt,
                notes: $notes,
            );

            return Response::json([
                'booking_number' => $appointment->bookingNumber,
                'status' => $appointment->status->value,
                'scheduled_at' => $appointment->scheduledAt->format('c'),
            ], 201);
        } catch (BookingException $e) {
            return Response::json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /booking/{number}/status: check appointment status.
     */
    public function status(Request $request): Response
    {
        $number = (string) ($request->attributes['number'] ?? '');
        $appointment = $this->repository->findByBookingNumber($number);

        if ($appointment === null) {
            return Response::json([
                'error' => 'Appointment not found',
            ], 404);
        }

        return Response::json([
            'booking_number' => $appointment->bookingNumber,
            'status' => $appointment->status->value,
            'scheduled_at' => $appointment->scheduledAt->format('c'),
            'duration' => $appointment->duration,
            'deposit_paid' => $appointment->depositPaid,
        ]);
    }

    /**
     * POST /booking/available-slots; get available slots for a date (AJAX).
     */
    public function availableSlots(Request $request): Response
    {
        $dateRaw = (string) ($request->post['date'] ?? '');
        $duration = (int) ($request->post['duration'] ?? 60);

        if ($dateRaw === '') {
            return Response::json([
                'error' => 'Missing required field: date',
            ], 400);
        }

        $date = new DateTimeImmutable($dateRaw);
        $slots = $this->timeSlotManager->getAvailable($date, $duration);

        $slotData = [];
        foreach ($slots as $slot) {
            $slotData[] = [
                'id' => $slot->id,
                'start_time' => $slot->startTime->format('H:i'),
                'end_time' => $slot->endTime->format('H:i'),
                'duration_minutes' => $slot->durationMinutes(),
            ];
        }

        return Response::json(['slots' => $slotData]);
    }
}
