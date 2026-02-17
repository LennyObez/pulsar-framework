<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Tickets\Config\TicketsConfig;
use Pulsar\Extension\Tickets\Contracts\TicketCategoryRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketMessageRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketNumberGeneratorInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketServiceInterface;
use Pulsar\Extension\Tickets\Internal\Persistence\DbTicketCategoryRepository;
use Pulsar\Extension\Tickets\Internal\Persistence\DbTicketMessageRepository;
use Pulsar\Extension\Tickets\Internal\Persistence\DbTicketRepository;
use Pulsar\Extension\Tickets\Internal\Service\TicketAutoAssigner;
use Pulsar\Extension\Tickets\Internal\Service\TicketNumberGenerator;
use Pulsar\Extension\Tickets\Internal\Service\TicketService;
use Pulsar\Extension\Tickets\Internal\Service\TicketSlaMonitor;

/**
 * Service provider for the Tickets extension: wires all repository
 * and service bindings into the container.
 */
#[Internal(reason: 'Ticket service wiring; use interfaces for public API')]
final class TicketsServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $this->registerRepositories($container);
        $this->registerServices($container);
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            TicketRepositoryInterface::class,
            TicketCategoryRepositoryInterface::class,
            TicketMessageRepositoryInterface::class,
            TicketServiceInterface::class,
            TicketNumberGeneratorInterface::class,
            TicketAutoAssigner::class,
            TicketSlaMonitor::class,
        ];
    }

    private function registerRepositories(ContainerInterface $container): void
    {
        $container->singleton(TicketRepositoryInterface::class, static function (ContainerInterface $c): TicketRepositoryInterface {
            /** @var ConnectionInterface $connection */
            $connection = $c->get(ConnectionInterface::class);

            return new DbTicketRepository($connection);
        });

        $container->singleton(TicketCategoryRepositoryInterface::class, static function (ContainerInterface $c): TicketCategoryRepositoryInterface {
            /** @var ConnectionInterface $connection */
            $connection = $c->get(ConnectionInterface::class);

            return new DbTicketCategoryRepository($connection);
        });

        $container->singleton(TicketMessageRepositoryInterface::class, static function (ContainerInterface $c): TicketMessageRepositoryInterface {
            /** @var ConnectionInterface $connection */
            $connection = $c->get(ConnectionInterface::class);

            return new DbTicketMessageRepository($connection);
        });
    }

    private function registerServices(ContainerInterface $container): void
    {
        $container->singleton(TicketNumberGeneratorInterface::class, static function (ContainerInterface $c): TicketNumberGeneratorInterface {
            /** @var ConnectionInterface $connection */
            $connection = $c->get(ConnectionInterface::class);

            return new TicketNumberGenerator($connection);
        });

        $container->singleton(TicketServiceInterface::class, static function (ContainerInterface $c): TicketServiceInterface {
            /** @var TicketRepositoryInterface $ticketRepo */
            $ticketRepo = $c->get(TicketRepositoryInterface::class);

            /** @var TicketMessageRepositoryInterface $messageRepo */
            $messageRepo = $c->get(TicketMessageRepositoryInterface::class);

            /** @var TicketNumberGeneratorInterface $numberGenerator */
            $numberGenerator = $c->get(TicketNumberGeneratorInterface::class);

            /** @var EventDispatcherInterface $eventDispatcher */
            $eventDispatcher = $c->get(EventDispatcherInterface::class);

            $autoAssigner = $c->has(TicketAutoAssigner::class)
                ? $c->get(TicketAutoAssigner::class)
                : null;

            return new TicketService(
                $ticketRepo,
                $messageRepo,
                $numberGenerator,
                $eventDispatcher,
                $autoAssigner instanceof TicketAutoAssigner ? $autoAssigner : null,
            );
        });

        $container->singleton(TicketSlaMonitor::class, static function (ContainerInterface $c): TicketSlaMonitor {
            /** @var TicketsConfig $config */
            $config = $c->get(TicketsConfig::class);

            /** @var TicketRepositoryInterface $ticketRepo */
            $ticketRepo = $c->get(TicketRepositoryInterface::class);

            /** @var EventDispatcherInterface $eventDispatcher */
            $eventDispatcher = $c->get(EventDispatcherInterface::class);

            return new TicketSlaMonitor($config, $ticketRepo, $eventDispatcher);
        });
    }
}
