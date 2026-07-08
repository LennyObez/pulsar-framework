<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Booking\Domain\BookingConfig;
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
        /** @var BookingConfig|null $bound */
        $bound = $container->has(BookingConfig::class)
            ? $container->get(BookingConfig::class)
            : null;
        $config = $bound ?? BookingConfig::fromArray([]);

        // Opt-out: a project that owns "/booking" itself can disable these routes
        // (routes_enabled => false) so the extension never shadows an app route.
        if (!$config->routesEnabled) {
            return;
        }

        $prefix = $config->routePrefix;
        $adminPrefix = $config->adminRoutePrefix;

        // Front-office routes
        $router->get($prefix, [BookingController::class, 'form'], 'booking.form');
        $router->post($prefix, [BookingController::class, 'submit'], 'booking.submit');
        $router->get($prefix . '/{number}/status', [BookingController::class, 'status'], 'booking.status');
        $router->post($prefix . '/available-slots', [BookingController::class, 'availableSlots'], 'booking.available_slots');

        // Admin dashboard
        $router->get($adminPrefix, [AdminBookingDashboardController::class, 'index'], 'booking.admin.dashboard');

        // Admin appointment management
        $router->get($adminPrefix . '/appointments', [AdminAppointmentController::class, 'list'], 'booking.admin.appointments.list');
        $router->get($adminPrefix . '/appointments/{id}', [AdminAppointmentController::class, 'show'], 'booking.admin.appointments.show');
        $router->post($adminPrefix . '/appointments/{id}/confirm', [AdminAppointmentController::class, 'confirm'], 'booking.admin.appointments.confirm');
        $router->post($adminPrefix . '/appointments/{id}/cancel', [AdminAppointmentController::class, 'cancel'], 'booking.admin.appointments.cancel');
        $router->post($adminPrefix . '/appointments/{id}/reschedule', [AdminAppointmentController::class, 'reschedule'], 'booking.admin.appointments.reschedule');
        $router->post($adminPrefix . '/appointments/{id}/complete', [AdminAppointmentController::class, 'complete'], 'booking.admin.appointments.complete');
        $router->post($adminPrefix . '/appointments/{id}/no-show', [AdminAppointmentController::class, 'noShow'], 'booking.admin.appointments.no_show');

        // Admin service management
        $router->get($adminPrefix . '/services', [AdminServiceController::class, 'listServices'], 'booking.admin.services.list');
        $router->post($adminPrefix . '/services', [AdminServiceController::class, 'createService'], 'booking.admin.services.create');
        $router->put($adminPrefix . '/services/{id}', [AdminServiceController::class, 'updateService'], 'booking.admin.services.update');
        $router->delete($adminPrefix . '/services/{id}', [AdminServiceController::class, 'deleteService'], 'booking.admin.services.delete');

        // Admin categories
        $router->get($adminPrefix . '/categories', [AdminServiceController::class, 'listCategories'], 'booking.admin.categories.list');
        $router->post($adminPrefix . '/categories', [AdminServiceController::class, 'createCategory'], 'booking.admin.categories.create');

        // Admin settings
        $router->get($adminPrefix . '/settings', [AdminBookingSettingsController::class, 'show'], 'booking.admin.settings');
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
