<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions;

use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Subscriptions\Http\Controller\SubscriptionController;
use Pulsar\Extension\Subscriptions\Http\Controller\WebhookController;
use Pulsar\Extension\Subscriptions\Http\Middleware\SubscriptionTokenGuard;
use Pulsar\Extension\Subscriptions\Internal\AppStoreVerifier;
use Pulsar\Extension\Subscriptions\Internal\CompositeVerifier;
use Pulsar\Extension\Subscriptions\Internal\GooglePlayVerifier;
use Pulsar\Extension\Subscriptions\Internal\Persistence\DbSubscriptionRepository;
use Pulsar\Extension\Subscriptions\Internal\Persistence\DbWebhookEventRepository;
use Pulsar\Extension\Subscriptions\Internal\SubscriptionService;

use function is_string;
use function sodium_crypto_secretbox_keygen;

/**
 * Wires all subscription services, repositories, verifiers, and controllers.
 *
 * Binds:
 *  - SubscriptionRepositoryInterface → DbSubscriptionRepository
 *  - WebhookEventRepositoryInterface → DbWebhookEventRepository
 *  - SubscriptionVerifierInterface   → GooglePlayVerifier (delegates Apple to AppStoreVerifier)
 *  - SubscriptionService (orchestrator)
 *  - SubscriptionController, WebhookController
 *  - SubscriptionTokenGuard middleware
 */
#[Internal(reason: 'Subscription service wiring; use interfaces for public API')]
final class SubscriptionsServiceProvider implements ServiceProviderInterface
{
    #[Override]
    public function register(ContainerInterface $container): void
    {
        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);

        /** @var LoggerInterface $logger */
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : new NullLogger();

        // Repositories
        $subscriptionRepo = new DbSubscriptionRepository($connection);
        $container->instance(SubscriptionRepositoryInterface::class, $subscriptionRepo);

        $webhookRepo = new DbWebhookEventRepository($connection);
        $container->instance(WebhookEventRepositoryInterface::class, $webhookRepo);

        // Store verifiers
        $googleConfig = $this->resolveGoogleConfig($container);
        $appleConfig = $this->resolveAppleConfig($container);

        $verifier = new CompositeVerifier(
            new GooglePlayVerifier($googleConfig),
            new AppStoreVerifier($appleConfig),
        );
        $container->instance(SubscriptionVerifierInterface::class, $verifier);

        // Core service
        $subscriptionService = new SubscriptionService(
            $verifier,
            $subscriptionRepo,
            $webhookRepo,
            $logger,
        );
        $container->instance(SubscriptionService::class, $subscriptionService);

        // Webhook encryption key
        $encryptionKey = $this->resolveEncryptionKey($container);

        // Controllers
        $container->instance(
            SubscriptionController::class,
            new SubscriptionController($subscriptionService),
        );

        $container->instance(
            WebhookController::class,
            new WebhookController($subscriptionService, $encryptionKey),
        );

        // Token guard middleware (uses empty key by default; override in app)
        if (!$container->has(SubscriptionTokenGuard::class)) {
            $container->instance(
                SubscriptionTokenGuard::class,
                new SubscriptionTokenGuard(''),
            );
        }
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            SubscriptionRepositoryInterface::class,
            WebhookEventRepositoryInterface::class,
            SubscriptionVerifierInterface::class,
            SubscriptionService::class,
            SubscriptionController::class,
            WebhookController::class,
            SubscriptionTokenGuard::class,
        ];
    }

    /**
     * Resolve Google Play API configuration from the container.
     *
     * @return array{package_name: string, service_account_json: string, api_base_url?: string}
     */
    private function resolveGoogleConfig(ContainerInterface $container): array
    {
        if ($container->has('subscriptions.google.config')) {
            /** @var array{package_name: string, service_account_json: string, api_base_url?: string} $config */
            $config = $container->get('subscriptions.google.config');

            return $config;
        }

        return [
            'package_name' => '',
            'service_account_json' => '',
        ];
    }

    /**
     * Resolve Apple App Store API configuration from the container.
     *
     * @return array{bundle_id: string, issuer_id: string, key_id: string, private_key_path: string, environment?: string}
     */
    private function resolveAppleConfig(ContainerInterface $container): array
    {
        if ($container->has('subscriptions.apple.config')) {
            /** @var array{bundle_id: string, issuer_id: string, key_id: string, private_key_path: string, environment?: string} $config */
            $config = $container->get('subscriptions.apple.config');

            return $config;
        }

        return [
            'bundle_id' => '',
            'issuer_id' => '',
            'key_id' => '',
            'private_key_path' => '',
        ];
    }

    /**
     * Resolve the webhook payload encryption key.
     *
     * Falls back to generating a random key if none is configured
     * (suitable for development; production should provide a stable key).
     */
    private function resolveEncryptionKey(ContainerInterface $container): string
    {
        if ($container->has('subscriptions.webhook.encryption_key')) {
            /** @var mixed $key */
            $key = $container->get('subscriptions.webhook.encryption_key');

            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        return sodium_crypto_secretbox_keygen();
    }
}
