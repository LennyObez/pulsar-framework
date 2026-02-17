<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Messaging\Config\MessagingConfig;
use Pulsar\Extension\Messaging\Contracts\ConversationRepositoryInterface;
use Pulsar\Extension\Messaging\Contracts\MessageRepositoryInterface;
use Pulsar\Extension\Messaging\Contracts\MessagingServiceInterface;
use Pulsar\Extension\Messaging\Contracts\PresenceServiceInterface;
use Pulsar\Extension\Messaging\Internal\Persistence\DbConversationRepository;
use Pulsar\Extension\Messaging\Internal\Persistence\DbMessageRepository;
use Pulsar\Extension\Messaging\Internal\Service\InMemoryPresenceService;
use Pulsar\Extension\Messaging\Internal\Service\MessagingService;
use Pulsar\Extension\Messaging\WebSocket\MessagingWebSocketHandler;
use Pulsar\Extension\Messaging\WebSocket\PresenceTracker;
use Pulsar\Extension\Messaging\WebSocket\SignalingHandler;
use Pulsar\Extension\Messaging\WebSocket\TypingIndicator;
use Pulsar\WebSocket\BroadcastManagerInterface;

/**
 * Service provider for the Messaging extension: wires all repository,
 * service, and WebSocket handler bindings into the container.
 */
#[Internal(reason: 'Messaging service wiring; use interfaces for public API')]
final class MessagingServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $this->registerRepositories($container);
        $this->registerServices($container);
        $this->registerWebSocketHandlers($container);
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            ConversationRepositoryInterface::class,
            MessageRepositoryInterface::class,
            MessagingServiceInterface::class,
            PresenceServiceInterface::class,
            MessagingWebSocketHandler::class,
            SignalingHandler::class,
            PresenceTracker::class,
            TypingIndicator::class,
        ];
    }

    private function registerRepositories(ContainerInterface $container): void
    {
        $container->singleton(ConversationRepositoryInterface::class, static function (ContainerInterface $c): ConversationRepositoryInterface {
            /** @var ConnectionInterface $connection */
            $connection = $c->get(ConnectionInterface::class);

            return new DbConversationRepository($connection);
        });

        $container->singleton(MessageRepositoryInterface::class, static function (ContainerInterface $c): MessageRepositoryInterface {
            /** @var ConnectionInterface $connection */
            $connection = $c->get(ConnectionInterface::class);

            return new DbMessageRepository($connection);
        });
    }

    private function registerServices(ContainerInterface $container): void
    {
        $container->singleton(PresenceServiceInterface::class, static function (): PresenceServiceInterface {
            return new InMemoryPresenceService();
        });

        $container->singleton(MessagingServiceInterface::class, static function (ContainerInterface $c): MessagingServiceInterface {
            /** @var ConversationRepositoryInterface $conversationRepo */
            $conversationRepo = $c->get(ConversationRepositoryInterface::class);

            /** @var MessageRepositoryInterface $messageRepo */
            $messageRepo = $c->get(MessageRepositoryInterface::class);

            return new MessagingService($conversationRepo, $messageRepo);
        });
    }

    private function registerWebSocketHandlers(ContainerInterface $container): void
    {
        $container->singleton(MessagingWebSocketHandler::class, static function (ContainerInterface $c): MessagingWebSocketHandler {
            /** @var MessagingServiceInterface $messagingService */
            $messagingService = $c->get(MessagingServiceInterface::class);

            /** @var ConversationRepositoryInterface $conversationRepo */
            $conversationRepo = $c->get(ConversationRepositoryInterface::class);

            /** @var BroadcastManagerInterface $broadcastManager */
            $broadcastManager = $c->get(BroadcastManagerInterface::class);

            $logger = $c->has(LoggerInterface::class)
                ? $c->get(LoggerInterface::class)
                : new NullLogger();

            /** @var LoggerInterface $logger */
            return new MessagingWebSocketHandler(
                $messagingService,
                $conversationRepo,
                $broadcastManager,
                $logger,
            );
        });

        $container->singleton(SignalingHandler::class, static function (ContainerInterface $c): SignalingHandler {
            /** @var BroadcastManagerInterface $broadcastManager */
            $broadcastManager = $c->get(BroadcastManagerInterface::class);

            /** @var MessagingConfig $config */
            $config = $c->get(MessagingConfig::class);

            $logger = $c->has(LoggerInterface::class)
                ? $c->get(LoggerInterface::class)
                : new NullLogger();

            /** @var LoggerInterface $logger */
            return new SignalingHandler($broadcastManager, $config->webRtc, $logger);
        });

        $container->singleton(PresenceTracker::class, static function (ContainerInterface $c): PresenceTracker {
            /** @var PresenceServiceInterface $presenceService */
            $presenceService = $c->get(PresenceServiceInterface::class);

            /** @var BroadcastManagerInterface $broadcastManager */
            $broadcastManager = $c->get(BroadcastManagerInterface::class);

            return new PresenceTracker($presenceService, $broadcastManager);
        });

        $container->singleton(TypingIndicator::class, static function (ContainerInterface $c): TypingIndicator {
            /** @var BroadcastManagerInterface $broadcastManager */
            $broadcastManager = $c->get(BroadcastManagerInterface::class);

            return new TypingIndicator($broadcastManager);
        });
    }
}
