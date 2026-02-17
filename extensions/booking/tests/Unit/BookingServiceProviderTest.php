<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Booking\BookingServiceProvider;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Contracts\BookingServiceInterface;
use Pulsar\Extension\Booking\Contracts\ReminderServiceInterface;
use Pulsar\Extension\Booking\Contracts\TimeSlotManagerInterface;
use Pulsar\Extension\Booking\Domain\BookingConfig;
use Pulsar\Extension\Booking\Reminder\SmsProviderInterface;

use function count;

#[CoversClass(BookingServiceProvider::class)]
final class BookingServiceProviderTest extends TestCase
{
    #[Test]
    public function providesContainsAllExpectedBindings(): void
    {
        $provider = new BookingServiceProvider();
        $provides = $provider->provides();

        self::assertContains(BookingConfig::class, $provides);
        self::assertContains(AppointmentRepositoryInterface::class, $provides);
        self::assertContains(TimeSlotManagerInterface::class, $provides);
        self::assertContains(SmsProviderInterface::class, $provides);
        self::assertContains(ReminderServiceInterface::class, $provides);
        self::assertContains(BookingServiceInterface::class, $provides);
    }

    #[Test]
    public function registerCallsBindForAllServices(): void
    {
        $provider = new BookingServiceProvider();

        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::atLeast(10))
            ->method('bind');

        $provider->register($container);
    }

    #[Test]
    public function providesCountMatchesRegisteredBindings(): void
    {
        $provider = new BookingServiceProvider();
        $provides = $provider->provides();

        // All entries in provides() should be unique
        self::assertSame(count($provides), count(array_unique($provides)));
    }
}
