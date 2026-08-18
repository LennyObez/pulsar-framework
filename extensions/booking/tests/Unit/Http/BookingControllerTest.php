<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Contracts\BookingServiceInterface;
use Pulsar\Extension\Booking\Contracts\TimeSlotManagerInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;
use Pulsar\Extension\Booking\Domain\TimeSlot;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Extension\Booking\Http\Controller\BookingController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

#[CoversClass(BookingController::class)]
final class BookingControllerTest extends TestCase
{
    private BookingServiceInterface&Stub $bookingService;
    private AppointmentRepositoryInterface&Stub $repository;
    private TimeSlotManagerInterface&Stub $timeSlotManager;
    private BookingController $controller;

    protected function setUp(): void
    {
        $this->bookingService = $this->createStub(BookingServiceInterface::class);
        $this->repository = $this->createStub(AppointmentRepositoryInterface::class);
        $this->timeSlotManager = $this->createStub(TimeSlotManagerInterface::class);

        $this->controller = new BookingController(
            $this->bookingService,
            $this->repository,
            $this->timeSlotManager,
        );
    }

    public function testFormReturnsHtml(): void
    {
        $response = $this->controller->form();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Book an Appointment', (string) $response->getBody());
    }

    public function testSubmitReturnsBadRequestWhenFieldsMissing(): void
    {
        $request = $this->makeRequest(Method::POST, '/booking', post: []);

        $response = $this->controller->submit($request);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testSubmitCreatesAppointmentSuccessfully(): void
    {
        $appointment = $this->makeAppointment();
        $this->bookingService->method('requestAppointment')->willReturn($appointment);

        $request = $this->makeRequest(Method::POST, '/booking', post: [
            'service_id' => 'svc-001',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'scheduled_at' => '2026-04-15 10:00:00',
        ]);

        $response = $this->controller->submit($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('BKG-2026-000001', (string) $response->getBody());
    }

    public function testSubmitReturns422OnBookingException(): void
    {
        $this->bookingService->method('requestAppointment')
            ->willThrowException(BookingException::tooSoon(24));

        $request = $this->makeRequest(Method::POST, '/booking', post: [
            'service_id' => 'svc-001',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'scheduled_at' => '2026-04-15 10:00:00',
        ]);

        $response = $this->controller->submit($request);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testStatusReturnsAppointment(): void
    {
        $appointment = $this->makeAppointment();
        $this->repository->method('findByBookingNumber')->willReturn($appointment);

        $request = $this->makeRequest(
            Method::GET,
            '/booking/BKG-2026-000001/status',
            attributes: ['number' => 'BKG-2026-000001'],
        );

        $response = $this->controller->status($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('BKG-2026-000001', (string) $response->getBody());
    }

    public function testStatusReturns404WhenNotFound(): void
    {
        $this->repository->method('findByBookingNumber')->willReturn(null);

        $request = $this->makeRequest(
            Method::GET,
            '/booking/NONEXISTENT/status',
            attributes: ['number' => 'NONEXISTENT'],
        );

        $response = $this->controller->status($request);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAvailableSlotsReturnsBadRequestWhenDateMissing(): void
    {
        $request = $this->makeRequest(Method::POST, '/booking/available-slots', post: []);

        $response = $this->controller->availableSlots($request);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAvailableSlotsReturnsSlots(): void
    {
        $slots = [
            new TimeSlot(
                id: 'slot-001',
                date: new DateTimeImmutable('2026-04-15'),
                startTime: new DateTimeImmutable('2026-04-15 10:00:00'),
                endTime: new DateTimeImmutable('2026-04-15 11:00:00'),
                available: true,
                appointmentId: null,
            ),
        ];
        $this->timeSlotManager->method('getAvailable')->willReturn($slots);

        $request = $this->makeRequest(Method::POST, '/booking/available-slots', post: [
            'date' => '2026-04-15',
            'duration' => '60',
        ]);

        $response = $this->controller->availableSlots($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('slot-001', (string) $response->getBody());
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     * @param array<string, mixed> $attributes
     */
    private function makeRequest(
        Method $method,
        string $path,
        array $post = [],
        array $query = [],
        array $attributes = [],
    ): Request {
        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag(),
            body: '',
            query: $query,
            post: $post,
            attributes: $attributes,
        );
    }

    private function makeAppointment(): Appointment
    {
        return new Appointment(
            id: 'apt-001',
            bookingNumber: 'BKG-2026-000001',
            serviceId: 'svc-001',
            customerId: 'cust-001',
            customerName: 'Jane Doe',
            customerEmail: 'jane@example.com',
            customerPhone: '+1234567890',
            status: AppointmentStatus::Requested,
            scheduledAt: new DateTimeImmutable('2026-04-15 10:00:00'),
            duration: 60,
            depositAmount: null,
            depositPaid: false,
            notes: '',
            reminderSent: false,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }
}
