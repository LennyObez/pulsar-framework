<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminAppointmentController;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminBookingDashboardController;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminBookingSettingsController;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminServiceController;
use Pulsar\Extension\Booking\Http\Controller\BookingController;
use Pulsar\Extension\Booking\ImportExport\BookingImportExportProvider;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\Routing\RouterInterface;

/**
 * Booking/appointment scheduling extension.
 *
 * Provides appointment booking, reminders (email + SMS), deposit collection
 * via Payments integration, and Google Calendar synchronization.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
final class BookingExtension implements ExtensionInterface, PostBootExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/booking';
    }

    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // Front-office routes
        $router->get('/booking', [BookingController::class, 'form'], 'booking.form');
        $router->post('/booking', [BookingController::class, 'submit'], 'booking.submit');
        $router->get('/booking/{number}/status', [BookingController::class, 'status'], 'booking.status');
        $router->post('/booking/available-slots', [BookingController::class, 'availableSlots'], 'booking.available_slots');

        // Admin dashboard
        $router->get('/admin/booking', [AdminBookingDashboardController::class, 'index'], 'booking.admin.dashboard');

        // Admin appointment management
        $router->get('/admin/booking/appointments', [AdminAppointmentController::class, 'list'], 'booking.admin.appointments.list');
        $router->get('/admin/booking/appointments/{id}', [AdminAppointmentController::class, 'show'], 'booking.admin.appointments.show');
        $router->post('/admin/booking/appointments/{id}/confirm', [AdminAppointmentController::class, 'confirm'], 'booking.admin.appointments.confirm');
        $router->post('/admin/booking/appointments/{id}/cancel', [AdminAppointmentController::class, 'cancel'], 'booking.admin.appointments.cancel');
        $router->post('/admin/booking/appointments/{id}/reschedule', [AdminAppointmentController::class, 'reschedule'], 'booking.admin.appointments.reschedule');
        $router->post('/admin/booking/appointments/{id}/complete', [AdminAppointmentController::class, 'complete'], 'booking.admin.appointments.complete');
        $router->post('/admin/booking/appointments/{id}/no-show', [AdminAppointmentController::class, 'noShow'], 'booking.admin.appointments.no_show');

        // Admin service management
        $router->get('/admin/booking/services', [AdminServiceController::class, 'listServices'], 'booking.admin.services.list');
        $router->post('/admin/booking/services', [AdminServiceController::class, 'createService'], 'booking.admin.services.create');
        $router->put('/admin/booking/services/{id}', [AdminServiceController::class, 'updateService'], 'booking.admin.services.update');
        $router->delete('/admin/booking/services/{id}', [AdminServiceController::class, 'deleteService'], 'booking.admin.services.delete');

        // Admin categories
        $router->get('/admin/booking/categories', [AdminServiceController::class, 'listCategories'], 'booking.admin.categories.list');
        $router->post('/admin/booking/categories', [AdminServiceController::class, 'createCategory'], 'booking.admin.categories.create');

        // Admin settings
        $router->get('/admin/booking/settings', [AdminBookingSettingsController::class, 'show'], 'booking.admin.settings');
    }

    public function postBoot(ContainerInterface $container): void
    {
        $this->registerImportExportProvider($container);
    }

    private function registerImportExportProvider(ContainerInterface $container): void
    {
        if (!$container->has(ImportExportRegistry::class)) {
            return;
        }

        /** @var ImportExportRegistry $registry */
        $registry = $container->get(ImportExportRegistry::class);

        $registry->register(new BookingImportExportProvider());
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            BookingServiceProvider::class,
        ];
    }
}
