<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Booking\BookingExtension;
use Pulsar\Extension\Booking\BookingServiceProvider;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\Routing\RouterInterface;

#[CoversClass(BookingExtension::class)]
final class BookingExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarBooking(): void
    {
        $extension = new BookingExtension();

        self::assertSame('pulsar/booking', $extension->name());
    }

    #[Test]
    public function providersReturnsBookingServiceProvider(): void
    {
        $extension = new BookingExtension();
        $providers = $extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(BookingServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function bootRegistersRoutes(): void
    {
        $extension = new BookingExtension();

        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        // Verify multiple routes are registered (front-office + admin)
        $router->expects(self::atLeast(3))->method('get');
        $router->expects(self::atLeast(3))->method('post');

        $extension->boot($container, $router);
    }

    #[Test]
    public function postBootRegistersImportExportProviderWhenRegistryAvailable(): void
    {
        $extension = new BookingExtension();

        $registry = new ImportExportRegistry();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            [ImportExportRegistry::class, true],
        ]);
        $container->method('get')->willReturnMap([
            [ImportExportRegistry::class, $registry],
        ]);

        $extension->postBoot($container);

        // Verify the booking provider was registered by checking the registry has it
        self::assertTrue($registry->has('booking'));
    }

    #[Test]
    public function postBootSkipsWhenNoImportExportRegistry(): void
    {
        $extension = new BookingExtension();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        // Should not throw -- silently skips
        $extension->postBoot($container);
        self::assertSame('pulsar/booking', $extension->name());
    }
}
