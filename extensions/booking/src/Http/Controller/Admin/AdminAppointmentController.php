<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Http\Controller\Admin;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Contracts\BookingServiceInterface;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Request;

/**
 * Admin appointment management: list, detail, confirm, cancel, reschedule,
 * mark completed, mark no-show.
 */
#[Internal]
final readonly class AdminAppointmentController
{
    public function __construct(
        private AppointmentRepositoryInterface $repository,
        private BookingServiceInterface $bookingService,
    ) {}

    /**
     * GET /admin/booking/appointments: list appointments.
     */
    public function list(Request $request): Response
    {
        $statusFilter = (string) ($request->query['status'] ?? '');

        $appointments = $statusFilter !== ''
            ? $this->repository->findByStatus(AppointmentStatus::from($statusFilter))
            : $this->repository->findUpcoming();

        return Response::json([
            'appointments' => array_map(fn($a) => [
                'id' => $a->id,
                'booking_number' => $a->bookingNumber,
                'customer_name' => $a->customerName,
                'customer_email' => $a->customerEmail,
                'scheduled_at' => $a->scheduledAt->format('c'),
                'duration' => $a->duration,
                'status' => $a->status->value,
                'deposit_paid' => $a->depositPaid,
            ], $appointments),
        ]);
    }

    /**
     * GET /admin/booking/appointments/{id}: appointment detail.
     */
    public function show(Request $request): Response
    {
        $id = (string) ($request->attributes['id'] ?? '');
        $appointment = $this->repository->findById($id);

        if ($appointment === null) {
            return Response::json(['error' => 'Appointment not found'], 404);
        }

        return Response::json([
            'id' => $appointment->id,
            'booking_number' => $appointment->bookingNumber,
            'service_id' => $appointment->serviceId,
            'customer_id' => $appointment->customerId,
            'customer_name' => $appointment->customerName,
            'customer_email' => $appointment->customerEmail,
            'customer_phone' => $appointment->customerPhone,
            'status' => $appointment->status->value,
            'scheduled_at' => $appointment->scheduledAt->format('c'),
            'duration' => $appointment->duration,
            'deposit_amount' => $appointment->depositAmount?->format(),
            'deposit_paid' => $appointment->depositPaid,
            'notes' => $appointment->notes,
            'reminder_sent' => $appointment->reminderSent,
            'created_at' => $appointment->createdAt->format('c'),
            'updated_at' => $appointment->updatedAt->format('c'),
        ]);
    }

    /**
     * POST /admin/booking/appointments/{id}/confirm: confirm an appointment.
     */
    public function confirm(Request $request): Response
    {
        $id = (string) ($request->attributes['id'] ?? '');

        try {
            $appointment = $this->bookingService->confirm($id);
            return Response::json(['status' => $appointment->status->value]);
        } catch (BookingException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/booking/appointments/{id}/cancel: cancel an appointment.
     */
    public function cancel(Request $request): Response
    {
        $id = (string) ($request->attributes['id'] ?? '');

        try {
            $appointment = $this->bookingService->cancel($id);
            return Response::json(['status' => $appointment->status->value]);
        } catch (BookingException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/booking/appointments/{id}/reschedule: reschedule an appointment.
     */
    public function reschedule(Request $request): Response
    {
        $id = (string) ($request->attributes['id'] ?? '');
        $newDateRaw = (string) ($request->post['scheduled_at'] ?? '');

        if ($newDateRaw === '') {
            return Response::json(['error' => 'Missing required field: scheduled_at'], 400);
        }

        try {
            $appointment = $this->bookingService->reschedule($id, new DateTimeImmutable($newDateRaw));
            return Response::json([
                'status' => $appointment->status->value,
                'scheduled_at' => $appointment->scheduledAt->format('c'),
            ]);
        } catch (BookingException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/booking/appointments/{id}/complete: mark as completed.
     */
    public function complete(Request $request): Response
    {
        $id = (string) ($request->attributes['id'] ?? '');

        try {
            $appointment = $this->bookingService->markCompleted($id);
            return Response::json(['status' => $appointment->status->value]);
        } catch (BookingException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/booking/appointments/{id}/no-show: mark as no-show.
     */
    public function noShow(Request $request): Response
    {
        $id = (string) ($request->attributes['id'] ?? '');

        try {
            $appointment = $this->bookingService->markNoShow($id);
            return Response::json(['status' => $appointment->status->value]);
        } catch (BookingException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }
}
