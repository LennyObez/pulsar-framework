<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Contracts\BookingServiceInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminAppointmentController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

#[CoversClass(AdminAppointmentController::class)]
final class AdminAppointmentControllerTest extends TestCase
{
    private AppointmentRepositoryInterface&Stub $repository;
    private BookingServiceInterface&Stub $bookingService;
    private AdminAppointmentController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(AppointmentRepositoryInterface::class);
        $this->bookingService = $this->createStub(BookingServiceInterface::class);

        $this->controller = new AdminAppointmentController(
            $this->repository,
            $this->bookingService,
        );
    }

    #[Test]
    public function listReturnsUpcomingAppointmentsWhenNoFilter(): void
    {
        $appointment = $this->makeAppointment('appt-1', 'BK-001', AppointmentStatus::Confirmed);
        $this->repository->method('findUpcoming')->willReturn([$appointment]);

        $request = $this->buildRequest([]);
        $response = $this->controller->list($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['appointments']);
        self::assertSame('BK-001', $body['appointments'][0]['booking_number']);
    }

    #[Test]
    public function listFiltersAppointmentsByStatus(): void
    {
        $appointment = $this->makeAppointment('appt-2', 'BK-002', AppointmentStatus::Cancelled);
        $this->repository->method('findByStatus')->willReturn([$appointment]);

        $request = $this->buildRequest(['status' => 'cancelled']);
        $response = $this->controller->list($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('cancelled', $body['appointments'][0]['status']);
    }

    #[Test]
    public function showReturns404WhenAppointmentNotFound(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $request = $this->buildRequest([], ['id' => 'nonexistent']);
        $response = $this->controller->show($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function showReturnsAppointmentDetails(): void
    {
        $appointment = $this->makeAppointment('appt-3', 'BK-003', AppointmentStatus::Confirmed);
        $this->repository->method('findById')->willReturn($appointment);

        $request = $this->buildRequest([], ['id' => 'appt-3']);
        $response = $this->controller->show($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('appt-3', $body['id']);
        self::assertSame('BK-003', $body['booking_number']);
        self::assertSame('confirmed', $body['status']);
    }

    #[Test]
    public function confirmReturnsNewStatus(): void
    {
        $appointment = $this->makeAppointment('appt-4', 'BK-004', AppointmentStatus::Confirmed);
        $this->bookingService->method('confirm')->willReturn($appointment);

        $request = $this->buildRequest([], ['id' => 'appt-4']);
        $response = $this->controller->confirm($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('confirmed', $body['status']);
    }

    #[Test]
    public function confirmReturns422OnBookingException(): void
    {
        $this->bookingService->method('confirm')
            ->willThrowException(BookingException::appointmentNotFound('x'));

        $request = $this->buildRequest([], ['id' => 'x']);
        $response = $this->controller->confirm($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function cancelReturnsNewStatus(): void
    {
        $appointment = $this->makeAppointment('appt-5', 'BK-005', AppointmentStatus::Cancelled);
        $this->bookingService->method('cancel')->willReturn($appointment);

        $request = $this->buildRequest([], ['id' => 'appt-5']);
        $response = $this->controller->cancel($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('cancelled', $body['status']);
    }

    #[Test]
    public function cancelReturns422OnBookingException(): void
    {
        $this->bookingService->method('cancel')
            ->willThrowException(BookingException::invalidTransition('completed', 'cancelled'));

        $request = $this->buildRequest([], ['id' => 'x']);
        $response = $this->controller->cancel($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rescheduleReturns400WhenMissingScheduledAt(): void
    {
        $request = $this->buildRequest([], ['id' => 'appt-6'], []);
        $response = $this->controller->reschedule($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function rescheduleReturnsNewSchedule(): void
    {
        $appointment = $this->makeAppointment('appt-7', 'BK-007', AppointmentStatus::Rescheduled);
        $this->bookingService->method('reschedule')->willReturn($appointment);

        $request = $this->buildRequest([], ['id' => 'appt-7'], ['scheduled_at' => '2026-05-01 10:00:00']);
        $response = $this->controller->reschedule($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('rescheduled', $body['status']);
    }

    #[Test]
    public function completeReturnsNewStatus(): void
    {
        $appointment = $this->makeAppointment('appt-8', 'BK-008', AppointmentStatus::Completed);
        $this->bookingService->method('markCompleted')->willReturn($appointment);

        $request = $this->buildRequest([], ['id' => 'appt-8']);
        $response = $this->controller->complete($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('completed', $body['status']);
    }

    #[Test]
    public function noShowReturnsNewStatus(): void
    {
        $appointment = $this->makeAppointment('appt-9', 'BK-009', AppointmentStatus::NoShow);
        $this->bookingService->method('markNoShow')->willReturn($appointment);

        $request = $this->buildRequest([], ['id' => 'appt-9']);
        $response = $this->controller->noShow($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('no_show', $body['status']);
    }

    private function makeAppointment(string $id, string $bookingNumber, AppointmentStatus $status): Appointment
    {
        $now = new DateTimeImmutable();

        return new Appointment(
            id: $id,
            bookingNumber: $bookingNumber,
            serviceId: 'svc-1',
            customerId: 'cust-1',
            customerName: 'Jane Doe',
            customerEmail: 'jane@test.com',
            customerPhone: '+15551234567',
            status: $status,
            scheduledAt: $now->modify('+3 days'),
            duration: 60,
            depositAmount: null,
            depositPaid: false,
            notes: '',
            reminderSent: false,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $attributes
     * @param array<string, string>|null $post
     */
    private function buildRequest(array $query, array $attributes = [], ?array $post = null): Request
    {
        return new Request(
            method: Method::GET,
            uri: '/admin/booking/appointments',
            path: '/admin/booking/appointments',
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
            query: $query,
            post: $post ?? [],
            attributes: $attributes,
        );
    }
}
