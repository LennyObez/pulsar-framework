<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Tickets\Contracts\TicketCategoryRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketMessageRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketNumberGeneratorInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketServiceInterface;
use Pulsar\Extension\Tickets\Internal\Service\TicketAutoAssigner;
use Pulsar\Extension\Tickets\Internal\Service\TicketSlaMonitor;
use Pulsar\Extension\Tickets\TicketsServiceProvider;

#[CoversClass(TicketsServiceProvider::class)]
final class TicketsServiceProviderTest extends TestCase
{
    #[Test]
    public function providesReturnsAllBindingKeys(): void
    {
        $provider = new TicketsServiceProvider();
        $provides = $provider->provides();

        self::assertContains(TicketRepositoryInterface::class, $provides);
        self::assertContains(TicketCategoryRepositoryInterface::class, $provides);
        self::assertContains(TicketMessageRepositoryInterface::class, $provides);
        self::assertContains(TicketServiceInterface::class, $provides);
        self::assertContains(TicketNumberGeneratorInterface::class, $provides);
        self::assertContains(TicketAutoAssigner::class, $provides);
        self::assertContains(TicketSlaMonitor::class, $provides);
    }

    #[Test]
    public function providesReturnsSevenEntries(): void
    {
        $provider = new TicketsServiceProvider();

        self::assertCount(7, $provider->provides());
    }

    #[Test]
    public function registerCallsSingletonForRepositoriesAndServices(): void
    {
        $provider = new TicketsServiceProvider();

        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::atLeast(5))
            ->method('singleton');

        $provider->register($container);
    }
}
