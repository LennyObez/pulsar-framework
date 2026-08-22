<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminBookingDashboardController;

#[CoversClass(AdminBookingDashboardController::class)]
final class AdminBookingDashboardControllerTest extends TestCase
{
    #[Test]
    public function indexReturnsTodayUpcomingAndStats(): void
    {
        $now = new DateTimeImmutable();
        $appointment = new Appointment(
            id: 'appt-1',
            bookingNumber: 'BK-001',
            serviceId: 'svc-1',
            customerId: 'cust-1',
            customerName: 'Alice',
            customerEmail: 'alice@test.com',
            customerPhone: '+15559999999',
            status: AppointmentStatus::Confirmed,
            scheduledAt: $now->modify('+2 hours'),
            duration: 30,
            depositAmount: null,
            depositPaid: false,
            notes: '',
            reminderSent: false,
            createdAt: $now,
            updatedAt: $now,
        );

        $repository = $this->createStub(AppointmentRepositoryInterface::class);
        $repository->method('findByDate')->willReturn([$appointment]);
        $repository->method('findUpcoming')->willReturn([$appointment]);
        $repository->method('countByStatus')->willReturn(['confirmed' => 3, 'cancelled' => 1]);

        $controller = new AdminBookingDashboardController($repository);

        $response = $controller->index();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);

        self::assertCount(1, $body['today']);
        self::assertSame('BK-001', $body['today'][0]['booking_number']);
        self::assertSame('Alice', $body['today'][0]['customer_name']);

        self::assertCount(1, $body['upcoming']);
        self::assertSame('BK-001', $body['upcoming'][0]['booking_number']);

        self::assertSame(3, $body['stats']['confirmed']);
        self::assertSame(1, $body['stats']['cancelled']);
    }

    #[Test]
    public function indexReturnsEmptyArraysWhenNoAppointments(): void
    {
        $repository = $this->createStub(AppointmentRepositoryInterface::class);
        $repository->method('findByDate')->willReturn([]);
        $repository->method('findUpcoming')->willReturn([]);
        $repository->method('countByStatus')->willReturn([]);

        $controller = new AdminBookingDashboardController($repository);

        $response = $controller->index();

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([], $body['today']);
        self::assertSame([], $body['upcoming']);
        self::assertSame([], $body['stats']);
    }
}
