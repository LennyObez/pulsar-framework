<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Http\Controller\Admin;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Http\Message\Response;

/**
 * Admin dashboard showing calendar view, today's appointments, and stats.
 */
#[Internal]
final readonly class AdminBookingDashboardController
{
    public function __construct(
        private AppointmentRepositoryInterface $repository,
    ) {}

    /**
     * GET /admin/booking: dashboard overview.
     */
    public function index(): Response
    {
        $today = new DateTimeImmutable('today');
        $todayEnd = new DateTimeImmutable('tomorrow');

        $todaysAppointments = $this->repository->findByDate($today);
        $upcomingAppointments = $this->repository->findUpcoming(10);
        $stats = $this->repository->countByStatus($today, $todayEnd);

        return Response::json([
            'today' => array_map(fn($a) => [
                'id' => $a->id,
                'booking_number' => $a->bookingNumber,
                'customer_name' => $a->customerName,
                'scheduled_at' => $a->scheduledAt->format('H:i'),
                'duration' => $a->duration,
                'status' => $a->status->value,
            ], $todaysAppointments),
            'upcoming' => array_map(fn($a) => [
                'id' => $a->id,
                'booking_number' => $a->bookingNumber,
                'customer_name' => $a->customerName,
                'scheduled_at' => $a->scheduledAt->format('c'),
                'status' => $a->status->value,
            ], $upcomingAppointments),
            'stats' => $stats,
        ]);
    }
}
