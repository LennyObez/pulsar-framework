<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\NotificationConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Notification\Channel\BroadcastChannel;
use Pulsar\Notification\Channel\DatabaseChannel;
use Pulsar\Notification\Channel\LogChannel;
use Pulsar\Notification\Channel\MailChannel;
use Pulsar\Notification\Channel\PushChannel;
use Pulsar\Notification\Channel\SlackChannel;
use Pulsar\Notification\Channel\SmsChannel;
use Pulsar\Notification\Channel\WebhookChannel;
use Pulsar\Notification\Consent\LegalBasisRegistry;
use Pulsar\Notification\Consent\NotificationClassificationRegistry;
use Pulsar\Notification\Consent\PreferenceStoreInterface;
use Pulsar\Notification\DatabaseNotificationStoreInterface;
use Pulsar\Notification\NotificationChannelInterface;
use Pulsar\Notification\NotificationHttpClientInterface;
use Pulsar\Notification\NotificationManager;
use Pulsar\Notification\NotificationManagerInterface;
use Pulsar\Notification\SmsGatewayInterface;
use Pulsar\Routing\Router;
use Pulsar\WebSocket\BroadcastManagerInterface as WebSocketBroadcastManagerInterface;

#[Internal]
final readonly class NotificationWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(NotificationConfig::class)) {
            return;
        }

        /** @var NotificationConfig $notificationConfig */
        $notificationConfig = $repository->get(NotificationConfig::class);
        $container->instance(NotificationConfig::class, $notificationConfig);

        if (!$notificationConfig->enabled) {
            return;
        }

        // Resolve optional dependencies
        $eventDispatcher = $container->has(EventDispatcherInterface::class)
            ? $container->get(EventDispatcherInterface::class)
            : null;

        /** @var EventDispatcherInterface|null $eventDispatcher */
        $auditLogger = $container->has(AuditLoggerInterface::class)
            ? $container->get(AuditLoggerInterface::class)
            : null;

        /** @var AuditLoggerInterface|null $auditLogger */
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */

        // Classification registry (always created, supports attribute-based classification)
        $classificationRegistry = new NotificationClassificationRegistry();
        $container->instance(NotificationClassificationRegistry::class, $classificationRegistry);

        // Preference store (port-based, injected by application)
        $preferenceStore = $container->has(PreferenceStoreInterface::class)
            ? $container->get(PreferenceStoreInterface::class)
            : null;

        /** @var PreferenceStoreInterface|null $preferenceStore */

        // Build available channels
        /** @var array<string, NotificationChannelInterface> $channels */
        $channels = [];

        // Mail channel (requires MailManager)
        if ($container->has(MailManagerInterface::class)) {
            /** @var MailManagerInterface $mailManager */
            $mailManager = $container->get(MailManagerInterface::class);
            $channels['mail'] = new MailChannel(
                $mailManager,
                $classificationRegistry,
                $notificationConfig->unsubscribeUrlPattern,
            );
        }

        // SMS channel (requires gateway adapter)
        if ($container->has(SmsGatewayInterface::class)) {
            /** @var SmsGatewayInterface $smsGateway */
            $smsGateway = $container->get(SmsGatewayInterface::class);
            $channels['sms'] = new SmsChannel($smsGateway);
        }

        // Database channel (requires store adapter)
        if ($container->has(DatabaseNotificationStoreInterface::class)) {
            /** @var DatabaseNotificationStoreInterface $dbStore */
            $dbStore = $container->get(DatabaseNotificationStoreInterface::class);
            $channels['database'] = new DatabaseChannel($dbStore);
        }

        // Slack and webhook channels (require HTTP client)
        if ($container->has(NotificationHttpClientInterface::class)) {
            /** @var NotificationHttpClientInterface $httpClient */
            $httpClient = $container->get(NotificationHttpClientInterface::class);
            $channels['slack'] = new SlackChannel($httpClient);
            $channels['webhook'] = new WebhookChannel($httpClient);
        }

        // Broadcast channel (requires WebSocket broadcast manager)
        if ($container->has(WebSocketBroadcastManagerInterface::class)) {
            /** @var WebSocketBroadcastManagerInterface $broadcastManager */
            $broadcastManager = $container->get(WebSocketBroadcastManagerInterface::class);
            $channels['broadcast'] = new BroadcastChannel($broadcastManager);
        }

        // Push channel (requires HTTP client + FCM project ID + OAuth token)
        $fcmProjectId = $notificationConfig->fcmProjectId ?? null;
        $fcmOAuthToken = $notificationConfig->fcmOAuthToken ?? null;
        if ($container->has(NotificationHttpClientInterface::class) && $fcmProjectId !== null && $fcmProjectId !== '' && $fcmOAuthToken !== null && $fcmOAuthToken !== '') {
            /** @var NotificationHttpClientInterface $pushHttpClient */
            $pushHttpClient = $container->get(NotificationHttpClientInterface::class);
            $channels['push'] = new PushChannel($pushHttpClient, $fcmProjectId, $fcmOAuthToken);
        }

        // Log channel (requires logger)
        if ($logger !== null) {
            $channels['log'] = new LogChannel($logger);
        }

        // Legal basis registry (always available; validated at boot in regulated mode)
        $legalBasisRegistry = new LegalBasisRegistry();
        $container->instance(LegalBasisRegistry::class, $legalBasisRegistry);

        // Notification manager
        $notificationManager = new NotificationManager(
            $notificationConfig,
            $channels,
            $eventDispatcher,
            $auditLogger,
            $logger,
            $classificationRegistry,
            $preferenceStore,
            $notificationConfig->regulated ? $legalBasisRegistry : null,
        );
        $container->instance(NotificationManager::class, $notificationManager);
        $container->instance(NotificationManagerInterface::class, $notificationManager);

        // Validate legal basis mappings at boot in regulated mode
        if ($notificationConfig->regulated) {
            $legalBasisRegistry->validate();
        }
    }
}
