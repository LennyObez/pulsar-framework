<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Commerce\CheckoutServiceInterface;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\CouponRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DigitalAssetRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceRendererInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderExportServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PaymentGateway;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductVariantRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionServiceInterface;
use Pulsar\Extension\Cms\Commerce\ShippingCalculatorInterface;
use Pulsar\Extension\Cms\Commerce\TaxCalculatorInterface;
use Pulsar\Extension\Cms\Internal\Commerce\CheckoutService;
use Pulsar\Extension\Cms\Internal\Commerce\ConfigurableShippingCalculator;
use Pulsar\Extension\Cms\Internal\Commerce\DigitalDeliveryService;
use Pulsar\Extension\Cms\Internal\Commerce\HtmlInvoiceRenderer;
use Pulsar\Extension\Cms\Internal\Commerce\InvoiceService;
use Pulsar\Extension\Cms\Internal\Commerce\OrderService;
use Pulsar\Extension\Cms\Internal\Commerce\PromotionEngine;
use Pulsar\Extension\Cms\Internal\Commerce\TaxCalculator;
use Pulsar\Extension\Cms\Internal\Commerce\WebhookHandler;
use Pulsar\Extension\Cms\Internal\Security\CmsKeyManager;
use Pulsar\Extension\Cms\Internal\Tools\OrderExportService;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;

/**
 * Binds commerce services: checkout, tax, promotions, invoicing, digital delivery.
 */
#[Internal(reason: 'CMS service wiring; use interfaces for public API')]
final readonly class CmsCommerceProvider
{
    public function register(
        ContainerInterface $container,
        ConnectionInterface $connection,
        CommerceConfig $commerceConfig,
        ?EventDispatcherInterface $eventDispatcher,
        ?AuditLoggerInterface $auditLogger,
    ): void {
        // Tax calculator
        $taxCalculator = new TaxCalculator($commerceConfig);
        $container->instance(TaxCalculatorInterface::class, $taxCalculator);

        // Shipping calculator
        $shippingCalculator = new ConfigurableShippingCalculator($commerceConfig);
        $container->instance(ShippingCalculatorInterface::class, $shippingCalculator);

        // Promotion engine
        /** @var PromotionRepositoryInterface $promotionRepository */
        $promotionRepository = $container->get(PromotionRepositoryInterface::class);

        /** @var CouponRepositoryInterface $couponRepository */
        $couponRepository = $container->get(CouponRepositoryInterface::class);

        /** @var ConnectionInterface $db */
        $db = $container->get(ConnectionInterface::class);

        $promotionEngine = new PromotionEngine($promotionRepository, $couponRepository, $db);
        $container->instance(PromotionServiceInterface::class, $promotionEngine);

        // Invoice renderer
        $settingsService = $container->has(SettingsServiceInterface::class)
            ? $container->get(SettingsServiceInterface::class)
            : null;

        /** @var SettingsServiceInterface|null $settingsService */
        $invoiceRenderer = new HtmlInvoiceRenderer($settingsService);
        $container->instance(InvoiceRendererInterface::class, $invoiceRenderer);

        // Invoice service
        /** @var OrderRepositoryInterface $orderRepository */
        $orderRepository = $container->get(OrderRepositoryInterface::class);

        /** @var OrderItemRepositoryInterface $orderItemRepository */
        $orderItemRepository = $container->get(OrderItemRepositoryInterface::class);

        /** @var InvoiceRepositoryInterface $invoiceRepository */
        $invoiceRepository = $container->get(InvoiceRepositoryInterface::class);

        $invoiceService = new InvoiceService($orderRepository, $orderItemRepository, $invoiceRepository, $invoiceRenderer);
        $container->instance(InvoiceServiceInterface::class, $invoiceService);

        // Digital delivery service (requires CmsKeyManager)
        if ($container->has(CmsKeyManager::class)) {
            /** @var CmsKeyManager $cmsKeyManager */
            $cmsKeyManager = $container->get(CmsKeyManager::class);

            /** @var DigitalAssetRepositoryInterface $digitalAssetRepository */
            $digitalAssetRepository = $container->get(DigitalAssetRepositoryInterface::class);

            /** @var ProductRepositoryInterface $productRepository */
            $productRepository = $container->get(ProductRepositoryInterface::class);

            $digitalDelivery = new DigitalDeliveryService(
                $digitalAssetRepository,
                $orderItemRepository,
                $productRepository,
                $cmsKeyManager,
                $connection,
                $commerceConfig,
                $auditLogger,
            );
            $container->instance(DigitalDeliveryServiceInterface::class, $digitalDelivery);

            // Order export service
            $container->instance(
                OrderExportServiceInterface::class,
                new OrderExportService($orderRepository, $orderItemRepository, $auditLogger),
            );

            // Checkout service (requires EventDispatcher)
            if ($eventDispatcher !== null) {
                /** @var PaymentGateway|null $paymentGateway */
                $paymentGateway = $container->has(PaymentGateway::class)
                    ? $container->get(PaymentGateway::class)
                    : null;

                /** @var ProductVariantRepositoryInterface $variantRepository */
                $variantRepository = $container->get(ProductVariantRepositoryInterface::class);

                $checkoutService = new CheckoutService(
                    $productRepository,
                    $orderRepository,
                    $orderItemRepository,
                    $promotionEngine,
                    $taxCalculator,
                    $invoiceService,
                    $digitalDelivery,
                    $connection,
                    $commerceConfig,
                    $eventDispatcher,
                    $variantRepository,
                    $paymentGateway,
                    $auditLogger,
                );
                $container->instance(CheckoutServiceInterface::class, $checkoutService);

                // Order service (internal, used by webhook handler)
                $orderService = new OrderService(
                    $orderRepository,
                    $invoiceService,
                    $digitalDelivery,
                    $connection,
                    $eventDispatcher,
                    $paymentGateway,
                    $auditLogger,
                );
                $container->instance(OrderService::class, $orderService);

                // Webhook handler (requires PaymentGateway)
                if ($paymentGateway !== null) {
                    $container->instance(
                        WebhookHandler::class,
                        new WebhookHandler($orderService, $orderRepository, $paymentGateway, $connection, $auditLogger),
                    );
                }
            }
        }
    }
}
