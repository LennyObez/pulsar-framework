<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Reminder;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Domain\Appointment;
use Pulsar\Extension\Booking\Domain\AppointmentStatus;
use Pulsar\Extension\Booking\Domain\BookingConfig;
use Pulsar\Extension\Booking\Reminder\EmailReminderSender;
use Pulsar\Extension\Booking\Reminder\ReminderService;
use Pulsar\Extension\Booking\Reminder\SmsReminderSender;

#[CoversClass(ReminderService::class)]
final class ReminderServiceTest extends TestCase
{
    private AppointmentRepositoryInterface&Stub $repository;
    private EmailReminderSender&MockObject $emailSender;
    private SmsReminderSender&MockObject $smsSender;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(AppointmentRepositoryInterface::class);
        $this->emailSender = $this->createMock(EmailReminderSender::class);
        $this->smsSender = $this->createMock(SmsReminderSender::class);
    }

    public function testSendReminderSendsEmailWhenEnabled(): void
    {
        $config = BookingConfig::fromArray([
            'email_reminder_enabled' => true,
            'sms_reminder_enabled' => false,
        ]);

        $service = new ReminderService(
            $this->repository,
            $config,
            $this->emailSender,
            $this->smsSender,
            new NullLogger(),
        );

        $appointment = $this->makeAppointment();

        $this->emailSender->expects(self::once())->method('send');
        $this->smsSender->expects(self::never())->method('send');

        $service->sendReminder($appointment);
    }

    public function testSendReminderSendsSmsWhenEnabled(): void
    {
        $config = BookingConfig::fromArray([
            'email_reminder_enabled' => false,
            'sms_reminder_enabled' => true,
        ]);

        $service = new ReminderService(
            $this->repository,
            $config,
            $this->emailSender,
            $this->smsSender,
            new NullLogger(),
        );

        $appointment = $this->makeAppointment();

        $this->emailSender->expects(self::never())->method('send');
        $this->smsSender->expects(self::once())->method('send');

        $service->sendReminder($appointment);
    }

    public function testSendReminderSkipsWhenAlreadySent(): void
    {
        $config = BookingConfig::fromArray([
            'email_reminder_enabled' => true,
            'sms_reminder_enabled' => true,
        ]);

        $service = new ReminderService(
            $this->repository,
            $config,
            $this->emailSender,
            $this->smsSender,
            new NullLogger(),
        );

        $appointment = $this->makeAppointment(reminderSent: true);

        $this->emailSender->expects(self::never())->method('send');
        $this->smsSender->expects(self::never())->method('send');

        $service->sendReminder($appointment);
    }

    public function testScheduleRemindersProcessesAllDueAppointments(): void
    {
        $config = BookingConfig::fromArray([
            'email_reminder_enabled' => true,
            'sms_reminder_enabled' => false,
            'reminder_hours_before' => 24,
        ]);

        $appointments = [
            $this->makeAppointment(),
            $this->makeAppointment(id: 'apt-002'),
        ];

        $this->repository->method('findNeedingReminder')->willReturn($appointments);

        $service = new ReminderService(
            $this->repository,
            $config,
            $this->emailSender,
            null,
            new NullLogger(),
        );

        $this->emailSender->expects(self::exactly(2))->method('send');

        $count = $service->scheduleReminders();

        self::assertSame(2, $count);
    }

    public function testSendReminderSkipsSmsWhenNoPhone(): void
    {
        $config = BookingConfig::fromArray([
            'email_reminder_enabled' => false,
            'sms_reminder_enabled' => true,
        ]);

        $service = new ReminderService(
            $this->repository,
            $config,
            $this->emailSender,
            $this->smsSender,
            new NullLogger(),
        );

        $appointment = $this->makeAppointment(phone: '');

        $this->smsSender->expects(self::never())->method('send');

        $service->sendReminder($appointment);
    }

    private function makeAppointment(
        string $id = 'apt-001',
        bool $reminderSent = false,
        string $phone = '+1234567890',
    ): Appointment {
        return new Appointment(
            id: $id,
            bookingNumber: 'BKG-2026-000001',
            serviceId: 'svc-001',
            customerId: 'cust-001',
            customerName: 'Jane Doe',
            customerEmail: 'jane@example.com',
            customerPhone: $phone,
            status: AppointmentStatus::Confirmed,
            scheduledAt: new DateTimeImmutable('+1 day'),
            duration: 60,
            depositAmount: null,
            depositPaid: false,
            notes: '',
            reminderSent: $reminderSent,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }
}
