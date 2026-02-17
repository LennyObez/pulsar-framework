<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Booking\Calendar\GoogleCalendarConfig;
use Pulsar\Extension\Booking\Calendar\GoogleCalendarSync;
use Pulsar\Extension\Booking\Calendar\GoogleCalendarSyncInterface;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Contracts\BookingServiceInterface;
use Pulsar\Extension\Booking\Contracts\ReminderServiceInterface;
use Pulsar\Extension\Booking\Contracts\TimeSlotManagerInterface;
use Pulsar\Extension\Booking\Domain\BookingConfig;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminAppointmentController;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminBookingDashboardController;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminBookingSettingsController;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminServiceController;
use Pulsar\Extension\Booking\Http\Controller\BookingController;
use Pulsar\Extension\Booking\Internal\BookingNumberGenerator;
use Pulsar\Extension\Booking\Internal\BookingService;
use Pulsar\Extension\Booking\Internal\DbAppointmentRepository;
use Pulsar\Extension\Booking\Internal\TimeSlotManager;
use Pulsar\Extension\Booking\Reminder\EmailReminderSender;
use Pulsar\Extension\Booking\Reminder\ReminderSchedulerJob;
use Pulsar\Extension\Booking\Reminder\ReminderService;
use Pulsar\Extension\Booking\Reminder\SmsProviderInterface;
use Pulsar\Extension\Booking\Reminder\SmsReminderSender;
use Pulsar\Extension\Booking\Reminder\TwilioSmsProvider;
use Pulsar\Extension\Booking\Reminder\VonageSmsProvider;
use Pulsar\Http\Client\HttpClientInterface;
use Pulsar\Mail\MailManagerInterface;

/**
 * Service provider for the booking extension.
 */
final class BookingServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Config
        $container->bind(BookingConfig::class, static function () use ($container): BookingConfig {
            /** @var array<string, mixed> $configData */
            $configData = [];

            if ($container->has('config.booking')) {
                /** @var array<string, mixed> $configData */
                $configData = $container->get('config.booking');
            }

            return BookingConfig::fromArray($configData);
        });

        // Google Calendar config
        $container->bind(GoogleCalendarConfig::class, static function () use ($container): GoogleCalendarConfig {
            /** @var BookingConfig $config */
            $config = $container->get(BookingConfig::class);

            return new GoogleCalendarConfig(
                calendarId: $config->googleCalendarId,
                serviceAccountKeyPath: $config->googleServiceAccountKeyPath,
                enabled: $config->googleCalendarEnabled,
            );
        });

        // Number generator
        $container->bind(BookingNumberGenerator::class, BookingNumberGenerator::class);

        // Repository
        $container->bind(DbAppointmentRepository::class, DbAppointmentRepository::class);
        $container->bind(AppointmentRepositoryInterface::class, DbAppointmentRepository::class);

        // Time slot manager
        $container->bind(TimeSlotManager::class, TimeSlotManager::class);
        $container->bind(TimeSlotManagerInterface::class, TimeSlotManager::class);

        // SMS provider
        $container->bind(SmsProviderInterface::class, static function () use ($container): SmsProviderInterface {
            /** @var BookingConfig $config */
            $config = $container->get(BookingConfig::class);

            /** @var HttpClientInterface $httpClient */
            $httpClient = $container->get(HttpClientInterface::class);

            return match ($config->smsProvider) {
                'vonage' => new VonageSmsProvider(
                    $httpClient,
                    $config->vonageApiKey,
                    $config->vonageApiSecret,
                    $config->vonageFromNumber,
                ),
                default => new TwilioSmsProvider(
                    $httpClient,
                    $config->twilioSid,
                    $config->twilioAuthToken,
                    $config->twilioFromNumber,
                ),
            };
        });

        // Reminder senders
        $container->bind(EmailReminderSender::class, static function () use ($container): EmailReminderSender {
            /** @var MailManagerInterface $mailManager */
            $mailManager = $container->get(MailManagerInterface::class);

            return new EmailReminderSender($mailManager);
        });

        $container->bind(SmsReminderSender::class, static function () use ($container): SmsReminderSender {
            /** @var SmsProviderInterface $smsProvider */
            $smsProvider = $container->get(SmsProviderInterface::class);

            return new SmsReminderSender($smsProvider);
        });

        // Reminder service
        $container->bind(ReminderService::class, ReminderService::class);
        $container->bind(ReminderServiceInterface::class, ReminderService::class);

        // Booking service
        $container->bind(BookingService::class, BookingService::class);
        $container->bind(BookingServiceInterface::class, BookingService::class);

        // Google Calendar sync
        $container->bind(GoogleCalendarSync::class, GoogleCalendarSync::class);
        $container->bind(GoogleCalendarSyncInterface::class, GoogleCalendarSync::class);

        // Scheduler job
        $container->bind(ReminderSchedulerJob::class, ReminderSchedulerJob::class);

        // Controllers
        $container->bind(BookingController::class, BookingController::class);
        $container->bind(AdminBookingDashboardController::class, AdminBookingDashboardController::class);
        $container->bind(AdminAppointmentController::class, AdminAppointmentController::class);
        $container->bind(AdminServiceController::class, AdminServiceController::class);
        $container->bind(AdminBookingSettingsController::class, AdminBookingSettingsController::class);
    }

    public function provides(): array
    {
        return [
            BookingConfig::class,
            GoogleCalendarConfig::class,
            BookingNumberGenerator::class,
            DbAppointmentRepository::class,
            AppointmentRepositoryInterface::class,
            TimeSlotManager::class,
            TimeSlotManagerInterface::class,
            SmsProviderInterface::class,
            EmailReminderSender::class,
            SmsReminderSender::class,
            ReminderService::class,
            ReminderServiceInterface::class,
            BookingService::class,
            BookingServiceInterface::class,
            GoogleCalendarSync::class,
            GoogleCalendarSyncInterface::class,
            ReminderSchedulerJob::class,
            BookingController::class,
            AdminBookingDashboardController::class,
            AdminAppointmentController::class,
            AdminServiceController::class,
            AdminBookingSettingsController::class,
        ];
    }
}
