<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Features\ProcessWebhook\WebhookController as LegacyWebhookController;
use Pulsar\Extension\Payments\Http\Controller\Api\PaymentApiController;
use Pulsar\Extension\Payments\Http\Controller\Api\PricingApiController;
use Pulsar\Extension\Payments\Http\Controller\CheckoutController;
use Pulsar\Extension\Payments\Http\Controller\InvoiceController;
use Pulsar\Extension\Payments\Http\Controller\SubscriptionController;
use Pulsar\Extension\Payments\Http\Controller\WebhookController;
use Pulsar\Extension\Payments\Http\Middleware\PaymentSecurityMiddleware;
use Pulsar\Extension\Payments\ImportExport\PaymentsImportExportProvider;
use Pulsar\Http\Method;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;

/**
 * Unified payments extension.
 *
 * Provides one-time payments, recurring subscriptions, invoicing,
 * mobile in-app purchase verification, and multi-gateway support
 * (Stripe, PayPal, SEPA, App Store, Google Play).
 *
 * Merges the former pulsar/payments and pulsar/subscriptions extensions.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 */
final class PaymentsExtension implements ExtensionInterface, PostBootExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/payments';
    }

    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        /** @var PaymentsConfig $config */
        $config = $container->get(PaymentsConfig::class);

        // Legacy webhook route (backward compatible)
        $router->post(
            $config->webhook->path,
            [LegacyWebhookController::class, 'handle'],
            'payments.webhook',
        );

        // Checkout routes
        $this->screenedPost($router, '/payments/checkout', [CheckoutController::class, 'create'], 'payments.checkout.create');
        $this->screenedPost($router, '/payments/checkout/{intentId}/capture', [CheckoutController::class, 'capture'], 'payments.checkout.capture');

        // Subscription routes
        $this->screenedPost($router, '/payments/subscriptions', [SubscriptionController::class, 'create'], 'payments.subscriptions.create');
        $router->get('/payments/subscriptions', [SubscriptionController::class, 'list'], 'payments.subscriptions.list');
        $this->screenedPost($router, '/payments/subscriptions/{id}/cancel', [SubscriptionController::class, 'cancel'], 'payments.subscriptions.cancel');
        $this->screenedPost($router, '/payments/subscriptions/{id}/pause', [SubscriptionController::class, 'pause'], 'payments.subscriptions.pause');
        $this->screenedPost($router, '/payments/subscriptions/{id}/resume', [SubscriptionController::class, 'resume'], 'payments.subscriptions.resume');

        // Invoice routes
        $router->get('/payments/invoices', [InvoiceController::class, 'list'], 'payments.invoices.list');
        $router->get('/payments/invoices/{id}', [InvoiceController::class, 'show'], 'payments.invoices.show');
        $router->get('/payments/invoices/{id}/download', [InvoiceController::class, 'download'], 'payments.invoices.download');

        // Gateway-specific webhook routes
        $router->post('/payments/webhooks/stripe', [WebhookController::class, 'stripe'], 'payments.webhooks.stripe');
        $router->post('/payments/webhooks/paypal', [WebhookController::class, 'paypal'], 'payments.webhooks.paypal');
        $router->post('/payments/webhooks/google-play', [WebhookController::class, 'googlePlay'], 'payments.webhooks.google_play');
        $router->post('/payments/webhooks/apple', [WebhookController::class, 'apple'], 'payments.webhooks.apple');
        $router->post('/payments/webhooks/payconiq', [WebhookController::class, 'payconiq'], 'payments.webhooks.payconiq');
        $router->post('/payments/webhooks/bancontact', [WebhookController::class, 'bancontact'], 'payments.webhooks.bancontact');

        // API routes
        $router->get('/api/v1/payments', [PaymentApiController::class, 'list'], 'payments.api.list');
        $router->get('/api/v1/payments/{id}', [PaymentApiController::class, 'show'], 'payments.api.show');
        // Mobile in-app-purchase subscription verification lives in the dedicated
        // pulsar/subscriptions extension, which owns /api/v1/subscriptions/*.
        $router->get('/api/v1/pricing', [PricingApiController::class, 'list'], 'payments.api.pricing');

        // Cart routes (front-office)
        $router->get('/cart', [Http\Controller\CartController::class, 'show'], 'payments.cart.show');
        $this->screenedPost($router, '/cart/add', [Http\Controller\CartController::class, 'add'], 'payments.cart.add');
        $this->screenedPost($router, '/cart/remove/{itemId}', [Http\Controller\CartController::class, 'remove'], 'payments.cart.remove');
        $this->screenedPost($router, '/cart/update/{itemId}', [Http\Controller\CartController::class, 'updateQuantity'], 'payments.cart.update');
        $this->screenedPost($router, '/cart/coupon', [Http\Controller\CartController::class, 'applyCoupon'], 'payments.cart.coupon');

        // Shipping admin routes
        $router->get('/admin/shipping', [Http\Controller\Admin\ShippingMethodController::class, 'index'], 'payments.admin.shipping.index');
        $router->get('/admin/shipping/create', [Http\Controller\Admin\ShippingMethodController::class, 'create'], 'payments.admin.shipping.create');
        $router->post('/admin/shipping', [Http\Controller\Admin\ShippingMethodController::class, 'store'], 'payments.admin.shipping.store');
        $router->get('/admin/shipping/{id}/edit', [Http\Controller\Admin\ShippingMethodController::class, 'edit'], 'payments.admin.shipping.edit');
        $router->put('/admin/shipping/{id}', [Http\Controller\Admin\ShippingMethodController::class, 'update'], 'payments.admin.shipping.update');
        $router->delete('/admin/shipping/{id}', [Http\Controller\Admin\ShippingMethodController::class, 'delete'], 'payments.admin.shipping.delete');
    }

    /**
     * Register a POST route behind the PCI-DSS body screen.
     *
     * The gateway webhook routes deliberately do not get it. Apple and Google
     * send purely numeric transaction identifiers of card-number length, and
     * about one in ten arbitrary numbers of that length passes the Luhn check
     * the screen uses, so screening them would reject live receipts.
     *
     * @param mixed $handler
     */
    private function screenedPost(RouterInterface $router, string $path, mixed $handler, string $name): void
    {
        $router->add(new Route(
            methods: [Method::POST],
            path: $path,
            handler: $handler,
            name: $name,
            middleware: [PaymentSecurityMiddleware::class],
        ));
    }

    public function postBoot(ContainerInterface $container): void
    {
        $this->registerAccountSections($container);
        $this->registerImportExportProvider($container);
    }

    /**
     * Register payment/order sections in the CMS account view (when CMS is active).
     */
    private function registerAccountSections(ContainerInterface $container): void
    {
        if (!$container->has(\Pulsar\Extension\Cms\Account\AccountSectionRegistry::class)) {
            return;
        }

        if (!$container->has(\Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface::class)
            || !$container->has(\Pulsar\Extension\Payments\Internal\Persistence\DbInvoiceRepository::class)
            || !$container->has(\Pulsar\Extension\Payments\Internal\Persistence\DbPaymentRepository::class)
        ) {
            return;
        }

        /** @var \Pulsar\Extension\Cms\Account\AccountSectionRegistry $registry */
        $registry = $container->get(\Pulsar\Extension\Cms\Account\AccountSectionRegistry::class);

        $registry->register(new Account\PaymentsAccountSectionProvider(
            $container->get(\Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface::class),
            $container->get(\Pulsar\Extension\Payments\Internal\Persistence\DbInvoiceRepository::class),
            $container->get(\Pulsar\Extension\Payments\Internal\Persistence\DbPaymentRepository::class),
        ));
    }

    private function registerImportExportProvider(ContainerInterface $container): void
    {
        if (!$container->has(ImportExportRegistry::class)) {
            return;
        }

        if (!$container->has(PaymentsConfig::class)) {
            return;
        }

        /** @var ImportExportRegistry $registry */
        $registry = $container->get(ImportExportRegistry::class);

        /** @var PaymentsConfig $config */
        $config = $container->get(PaymentsConfig::class);

        $registry->register(new PaymentsImportExportProvider($config));
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        return [
            PaymentsServiceProvider::class,
        ];
    }
}
