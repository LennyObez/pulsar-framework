<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging;

use Override;
use Pulsar\Api\Api;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Messaging\Config\MessagingConfig;
use Pulsar\Extension\Messaging\Http\Controller\ConversationController;
use Pulsar\Extension\Messaging\Http\Controller\MessageController;
use Pulsar\Extension\Messaging\Http\Controller\WebRtcController;
use Pulsar\Routing\RouterInterface;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Real-time messaging extension with end-to-end encryption and WebRTC signaling.
 *
 * Provides encrypted conversations (direct, group, channel), WebSocket-based
 * real-time delivery, presence tracking, typing indicators, and WebRTC
 * signaling for peer-to-peer audio/video calls. Designed for regulated,
 * mission-critical domains requiring zero-knowledge message privacy.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MessagingExtension implements ExtensionInterface, PreBootExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/messaging';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    #[Override]
    public function preBoot(ContainerInterface $container): void
    {
        if (!$container->has(MessagingConfig::class) && $container->has(ConfigManagerInterface::class)) {
            /** @var ConfigManagerInterface $configManager */
            $configManager = $container->get(ConfigManagerInterface::class);
            $configPath = $configManager->configPath();

            if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'messaging.php')) {
                /**
                 * @psalm-suppress UnresolvableInclude
                 * @var mixed $data
                 */
                $data = require $configPath . DIRECTORY_SEPARATOR . 'messaging.php';

                if (is_array($data)) {
                    /** @var array<string, mixed> $data */
                    $container->instance(MessagingConfig::class, MessagingConfig::fromArray($data));
                }
            }
        }

        if (!$container->has(MessagingConfig::class)) {
            $container->instance(MessagingConfig::class, MessagingConfig::fromArray([]));
        }
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $this->registerApiRoutes($router);
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            MessagingServiceProvider::class,
        ];
    }

    private function registerApiRoutes(RouterInterface $router): void
    {
        $prefix = '/api/v1/messaging';

        // Conversations
        $router->get("$prefix/conversations", [ConversationController::class, 'index'], 'messaging.api.conversations.index');
        $router->post("$prefix/conversations", [ConversationController::class, 'create'], 'messaging.api.conversations.create');
        $router->get("$prefix/conversations/{id}", [ConversationController::class, 'show'], 'messaging.api.conversations.show');

        // Messages
        $router->get("$prefix/conversations/{conversationId}/messages", [MessageController::class, 'index'], 'messaging.api.messages.index');
        $router->post("$prefix/conversations/{conversationId}/messages", [MessageController::class, 'send'], 'messaging.api.messages.send');
        $router->post("$prefix/conversations/{conversationId}/read", [MessageController::class, 'markRead'], 'messaging.api.messages.mark_read');

        // WebRTC
        $router->get("$prefix/webrtc/ice-servers", [WebRtcController::class, 'iceServers'], 'messaging.api.webrtc.ice_servers');
        $router->get("$prefix/webrtc/calls/{callId}", [WebRtcController::class, 'callStatus'], 'messaging.api.webrtc.call_status');
    }
}
