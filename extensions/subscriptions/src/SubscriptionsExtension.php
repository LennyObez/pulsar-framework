<?php

declare(strict_types=1);

namespace Pulsar\Extension\Subscriptions;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Subscriptions\Http\Controller\SubscriptionController;
use Pulsar\Extension\Subscriptions\Http\Controller\WebhookController;
use Pulsar\Routing\RouterInterface;

/**
 * Subscriptions extension for mobile app in-app purchase verification.
 *
 * Provides server-side subscription verification for Google Play and Apple
 * App Store, webhook processing for real-time notifications, and a REST API
 * for mobile clients to verify, query, and restore subscriptions.
 */
#[Api(since: '1.0.0')]
final readonly class SubscriptionsExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/subscriptions';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // All bindings are handled by SubscriptionsServiceProvider
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $this->registerApiRoutes($router);
        $this->registerWebhookRoutes($router);
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            SubscriptionsServiceProvider::class,
        ];
    }

    /**
     * Register authenticated subscription API routes.
     */
    private function registerApiRoutes(RouterInterface $router): void
    {
        $prefix = '/api/v1/subscriptions';

        $router->post(
            "$prefix/verify",
            [SubscriptionController::class, 'verify'],
            'subscriptions.api.verify',
        );

        $router->get(
            "$prefix/status",
            [SubscriptionController::class, 'status'],
            'subscriptions.api.status',
        );

        $router->post(
            "$prefix/restore",
            [SubscriptionController::class, 'restore'],
            'subscriptions.api.restore',
        );
    }

    /**
     * Register unauthenticated webhook receiver routes.
     */
    private function registerWebhookRoutes(RouterInterface $router): void
    {
        $prefix = '/api/v1/webhooks';

        $router->post(
            "$prefix/google-play",
            [WebhookController::class, 'googlePlay'],
            'subscriptions.webhooks.google_play',
        );

        $router->post(
            "$prefix/apple-sns",
            [WebhookController::class, 'appleSns'],
            'subscriptions.webhooks.apple_sns',
        );
    }
}
