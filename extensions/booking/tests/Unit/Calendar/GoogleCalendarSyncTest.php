<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Calendar;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Booking\Calendar\GoogleCalendarConfig;
use Pulsar\Extension\Booking\Calendar\GoogleCalendarSync;
use Pulsar\Extension\Booking\Exception\BookingException;
use Pulsar\Http\Client\HttpClientInterface;

#[CoversClass(GoogleCalendarSync::class)]
final class GoogleCalendarSyncTest extends TestCase
{
    private HttpClientInterface&Stub $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createStub(HttpClientInterface::class);
    }

    public function testCreateEventThrowsWhenKeyPathEmpty(): void
    {
        $config = new GoogleCalendarConfig(
            calendarId: 'primary',
            serviceAccountKeyPath: '',
            enabled: true,
        );

        $sync = new GoogleCalendarSync(
            $this->httpClient,
            $config,
            new NullLogger(),
        );

        $appointment = $this->makeAppointment();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('not configured');

        $sync->createEvent($appointment);
    }

    public function testCreateEventThrowsWhenKeyFileNotReadable(): void
    {
        $config = new GoogleCalendarConfig(
            calendarId: 'primary',
            serviceAccountKeyPath: '/nonexistent/path/key.json',
            enabled: true,
        );

        $sync = new GoogleCalendarSync(
            $this->httpClient,
            $config,
            new NullLogger(),
        );

        $appointment = $this->makeAppointment();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Cannot read');

        $sync->createEvent($appointment);
    }

    public function testGoogleCalendarConfigFromArray(): void
    {
        $config = GoogleCalendarConfig::fromArray([
            'calendar_id' => 'my-calendar',
            'service_account_key_path' => '/path/key.json',
            'enabled' => true,
        ]);

        self::assertSame('my-calendar', $config->calendarId);
        self::assertSame('/path/key.json', $config->serviceAccountKeyPath);
        self::assertTrue($config->enabled);
    }

    public function testGoogleCalendarConfigFromArrayDefaults(): void
    {
        $config = GoogleCalendarConfig::fromArray([]);

        self::assertSame('', $config->calendarId);
        self::assertSame('', $config->serviceAccountKeyPath);
        self::assertFalse($config->enabled);
    }

    private function makeAppointment(): \Pulsar\Extension\Booking\Domain\Appointment
    {
        return new \Pulsar\Extension\Booking\Domain\Appointment(
            id: 'apt-001',
            bookingNumber: 'BKG-2026-000001',
            serviceId: 'svc-001',
            customerId: 'cust-001',
            customerName: 'Jane Doe',
            customerEmail: 'jane@example.com',
            customerPhone: '+1234567890',
            status: \Pulsar\Extension\Booking\Domain\AppointmentStatus::Confirmed,
            scheduledAt: new DateTimeImmutable('2026-04-15 10:00:00'),
            duration: 60,
            depositAmount: null,
            depositPaid: false,
            notes: 'Test',
            reminderSent: false,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }
}
