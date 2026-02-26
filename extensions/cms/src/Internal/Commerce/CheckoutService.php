<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Commerce\CartValidationResult;
use Pulsar\Extension\Cms\Commerce\CheckoutServiceInterface;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderItem;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\OrderStatusStateMachine;
use Pulsar\Extension\Cms\Commerce\PaymentGateway;
use Pulsar\Extension\Cms\Commerce\PaymentResult;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductVariant;
use Pulsar\Extension\Cms\Commerce\ProductVariantRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionServiceInterface;
use Pulsar\Extension\Cms\Commerce\TaxCalculatorInterface;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function array_column;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function sprintf;

/**
 * Checkout flow orchestrator handling cart validation, order creation,
 * stock reservation, and payment processing.
 */
#[Internal(reason: 'Use CheckoutServiceInterface for public API')]
final readonly class CheckoutService implements CheckoutServiceInterface
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private OrderRepositoryInterface $orders,
        private OrderItemRepositoryInterface $orderItems,
        private PromotionServiceInterface $promotions,
        private TaxCalculatorInterface $taxCalculator,
        private InvoiceServiceInterface $invoiceService,
        private DigitalDeliveryServiceInterface $digitalDelivery,
        private ConnectionInterface $db,
        private CommerceConfig $config,
        private EventDispatcherInterface $events,
        private ProductVariantRepositoryInterface $variants,
        private ?PaymentGateway $paymentGateway = null,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function validateCart(array $cartItems): CartValidationResult
    {
        $errors = [];
        $validatedItems = [];

        foreach ($cartItems as $item) {
            $product = $this->products->findById($item['productId']);

            if ($product === null) {
                $errors[] = sprintf('Product not found: %s', $item['productId']);

                continue;
            }

            if (!$product->isActive()) {
                $errors[] = sprintf('Product is not available: %s', $product->sku);

                continue;
            }

            $variantId = $item['variantId'] ?? null;
            $variant = null;

            if ($variantId !== null) {
                $variant = $this->variants->findById($variantId);

                if ($variant === null) {
                    $errors[] = sprintf('Variant not found: %s', $variantId);

                    continue;
                }

                if (!$variant->isActive) {
                    $errors[] = sprintf('Variant is not available: %s', $variant->skuSuffix);

                    continue;
                }
            }

            $effectiveStock = $variant !== null ? $variant->stockQuantity : $product->stockQuantity;
            $effectivePrice = $variant !== null
                ? $product->priceAmount + $variant->priceModifier
                : $product->priceAmount;

            if ($effectiveStock > 0 && $effectiveStock < $item['quantity']) {
                $errors[] = sprintf(
                    'Insufficient stock for %s: requested %d, available %d',
                    $product->sku . ($variant !== null ? '-' . $variant->skuSuffix : ''),
                    $item['quantity'],
                    $effectiveStock,
                );

                continue;
            }

            if ($effectivePrice !== $item['unitPrice']) {
                $errors[] = sprintf(
                    'Price changed for %s: expected %d, current %d',
                    $product->sku . ($variant !== null ? '-' . $variant->skuSuffix : ''),
                    $item['unitPrice'],
                    $effectivePrice,
                );

                continue;
            }

            $validatedItems[] = [
                'productId' => $product->id,
                'variantId' => $variantId,
                'quantity' => $item['quantity'],
                'unitPrice' => $effectivePrice,
                'currency' => $product->priceCurrency,
            ];
        }

        return new CartValidationResult(
            isValid: $errors === [],
            errors: $errors,
            validatedItems: $validatedItems,
        );
    }

    public function createOrder(
        array $cartItems,
        string $customerEmail,
        array $billingAddress,
        ?array $shippingAddress = null,
        ?string $couponCode = null,
        ?string $customerId = null,
        ?string $tenantId = null,
    ): Order {
        return $this->db->transaction(function (ConnectionInterface $db) use (
            $cartItems,
            $customerEmail,
            $billingAddress,
            $shippingAddress,
            $couponCode,
            $customerId,
            $tenantId,
        ): Order {
            // Batch-load all products for this order
            $productIds = array_unique(array_column($cartItems, 'productId'));
            $products = $this->products->findByIds($productIds);

            // Batch-load all variants for this order
            $variantIds = array_values(array_unique(array_filter(array_column($cartItems, 'variantId'))));
            $variants = $variantIds !== [] ? $this->variants->findByIds($variantIds) : [];

            // Validate cart within transaction using pre-fetched products and variants
            $validation = $this->validateCartWithProducts($cartItems, $products, $variants);

            if (!$validation->isValid) {
                throw new CmsException('Cart validation failed: ' . implode('; ', $validation->errors));
            }

            // Reserve stock atomically (variant stock takes priority when present)
            foreach ($validation->validatedItems as $item) {
                $itemVariantId = $item['variantId'] ?? null;

                if ($itemVariantId !== null) {
                    $variant = $variants[$itemVariantId] ?? null;

                    if ($variant !== null && $variant->stockQuantity > 0) {
                        if (!$this->variants->reserveStock($itemVariantId, $item['quantity'])) {
                            throw new CmsException(sprintf('Failed to reserve stock for variant: %s', $itemVariantId));
                        }
                    }
                } else {
                    $product = $products[$item['productId']] ?? null;

                    if ($product !== null && $product->stockQuantity > 0) {
                        if (!$this->products->reserveStock($item['productId'], $item['quantity'])) {
                            throw new CmsException(sprintf('Failed to reserve stock for product: %s', $item['productId']));
                        }
                    }
                }
            }

            // Generate order number
            $orderNumber = $this->generateOrderNumber($db, $tenantId);
            $orderId = UuidGenerator::v7();
            $effectiveCustomerId = $customerId ?? $orderId;

            // Create order
            $order = Order::create(
                id: $orderId,
                orderNumber: $orderNumber,
                customerId: $effectiveCustomerId,
                customerEmail: $customerEmail,
                currency: $this->config->currency,
                billingAddress: $billingAddress,
                tenantId: $tenantId,
            );

            // Calculate subtotal and create order items
            $subtotal = 0;
            $orderItemsList = [];

            foreach ($validation->validatedItems as $item) {
                $lineTotal = $item['unitPrice'] * $item['quantity'];
                $subtotal += $lineTotal;

                $product = $products[$item['productId']] ?? null;
                $itemVariantId = $item['variantId'] ?? null;
                $variant = $itemVariantId !== null ? ($variants[$itemVariantId] ?? null) : null;

                $snapshot = $product !== null ? [
                    'sku' => $product->sku,
                    'priceAmount' => $product->priceAmount,
                    'priceCurrency' => $product->priceCurrency,
                    'digital' => $product->digital,
                    'taxCategory' => $product->taxCategory,
                ] : [];

                if ($variant !== null) {
                    $snapshot['variantSku'] = $variant->skuSuffix;
                    $snapshot['variantPriceModifier'] = $variant->priceModifier;
                }

                $orderItem = new OrderItem(
                    id: UuidGenerator::v7(),
                    orderId: $orderId,
                    productId: $item['productId'],
                    variantId: $itemVariantId,
                    quantity: $item['quantity'],
                    unitPrice: $item['unitPrice'],
                    totalPrice: $lineTotal,
                    taxAmount: 0,
                    discountAmount: 0,
                    productSnapshot: $snapshot,
                );

                $orderItemsList[] = $orderItem;
                $this->orderItems->save($orderItem);
            }

            // Apply coupon discount
            $discountAmount = 0;

            if ($couponCode !== null) {
                $promoResult = $this->promotions->validateCoupon($couponCode, $cartItems, $customerId);

                if ($promoResult->isValid && $promoResult->promotion !== null) {
                    $discountCalc = $this->promotions->calculateDiscount($promoResult->promotion, $cartItems);
                    $discountAmount = $discountCalc->totalDiscount;
                    $this->promotions->incrementUsage($promoResult->promotion->id, $customerId);
                }
            }

            // Calculate tax
            $taxAmount = 0;

            if ($this->config->taxRequired) {
                $taxItems = array_map(
                    static fn(OrderItem $oi): array => [
                        'productId' => $oi->productId,
                        'amount' => $oi->totalPrice,
                        'taxCategory' => $oi->productSnapshot['taxCategory'] ?? null,
                        'quantity' => $oi->quantity,
                    ],
                    $orderItemsList,
                );

                $taxResult = $this->taxCalculator->calculate($taxItems, $billingAddress['country']);
                $taxAmount = $taxResult->totalTax;
            }

            // Compute total
            $total = $subtotal - $discountAmount + $taxAmount;

            // Persist order with computed totals
            $order = new Order(
                id: $order->id,
                tenantId: $order->tenantId,
                orderNumber: $order->orderNumber,
                customerId: $order->customerId,
                customerEmail: $order->customerEmail,
                status: OrderStatus::PendingPayment,
                subtotal: $subtotal,
                taxAmount: $taxAmount,
                discountAmount: $discountAmount,
                shippingAmount: 0,
                shippingMethod: null,
                total: $total,
                amountRefunded: 0,
                currency: $order->currency,
                paymentIntentId: null,
                paymentStatus: PaymentStatus::Pending,
                billingAddress: $billingAddress,
                shippingAddress: $shippingAddress,
                notes: null,
                dataClassification: DataClassification::Pii,
                createdAt: $order->createdAt,
                updatedAt: new DateTimeImmutable(),
            );

            $this->orders->save($order);

            // Transition Cart -> PendingPayment
            OrderStatusStateMachine::transition(OrderStatus::Cart, OrderStatus::PendingPayment);

            // Dispatch event
            $this->events->dispatch(new OrderCreatedEvent($order));

            $this->auditLogger?->log(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                $customerId,
                'cms.commerce.order.created',
                "order:{$order->id}",
                ['orderNumber' => $order->orderNumber, 'total' => $total],
            );

            return $order;
        });
    }

    public function processPayment(string $orderId, array $paymentData): PaymentResult
    {
        $order = $this->orders->findById($orderId);

        if ($order === null) {
            throw CmsException::contentNotFound($orderId);
        }

        if ($this->paymentGateway === null) {
            // No payment gateway configured — auto-confirm for free/test orders
            $this->confirmOrder($order);

            return new PaymentResult(
                success: true,
                orderId: $orderId,
                paymentIntentId: null,
                requiresRedirect: false,
                redirectUrl: null,
            );
        }

        $idempotencyKey = sprintf('cms_order_%s', $orderId);

        $result = $this->paymentGateway->createPaymentIntent(
            $order->total,
            $order->currency,
            $idempotencyKey,
            ['orderId' => $orderId, 'orderNumber' => $order->orderNumber],
        );

        if ($result->success) {
            $this->confirmOrder($order, $result->paymentIntentId);
        }

        return $result;
    }

    public function cancelCheckout(string $orderId): void
    {
        $order = $this->orders->findById($orderId);

        if ($order === null) {
            throw CmsException::contentNotFound($orderId);
        }

        if ($order->status->isTerminal()) {
            return;
        }

        OrderStatusStateMachine::transition($order->status, OrderStatus::Cancelled);
        $this->orders->updateStatus($orderId, OrderStatus::Cancelled);

        // Restore reserved stock (variant stock when applicable)
        $items = $this->orderItems->findByOrder($orderId);

        foreach ($items as $item) {
            if ($item->variantId !== null) {
                $this->variants->restoreStock($item->variantId, $item->quantity);
            } else {
                $this->products->restoreStock($item->productId, $item->quantity);
            }
        }

        $this->events->dispatch(new OrderCancelledEvent($order));

        $this->auditLogger?->log(
            AuditEvent::DataModification,
            AuditOutcome::Success,
            null,
            'cms.commerce.checkout.cancelled',
            "order:{$orderId}",
            ['orderNumber' => $order->orderNumber],
        );
    }

    /**
     * @param list<array{productId: string, quantity: int, unitPrice: int, variantId?: string|null}> $cartItems
     * @param array<string, Product> $products Pre-fetched product map keyed by ID
     * @param array<string, ProductVariant> $variants Pre-fetched variant map keyed by ID
     */
    private function validateCartWithProducts(array $cartItems, array $products, array $variants = []): CartValidationResult
    {
        $errors = [];
        $validatedItems = [];

        foreach ($cartItems as $item) {
            $product = $products[$item['productId']] ?? null;

            if ($product === null) {
                $errors[] = sprintf('Product not found: %s', $item['productId']);

                continue;
            }

            if (!$product->isActive()) {
                $errors[] = sprintf('Product is not available: %s', $product->sku);

                continue;
            }

            $variantId = $item['variantId'] ?? null;
            $variant = null;

            if ($variantId !== null) {
                $variant = $variants[$variantId] ?? null;

                if ($variant === null) {
                    $errors[] = sprintf('Variant not found: %s', $variantId);

                    continue;
                }

                if (!$variant->isActive) {
                    $errors[] = sprintf('Variant is not available: %s', $variant->skuSuffix);

                    continue;
                }
            }

            $effectiveStock = $variant !== null ? $variant->stockQuantity : $product->stockQuantity;
            $effectivePrice = $variant !== null
                ? $product->priceAmount + $variant->priceModifier
                : $product->priceAmount;

            if ($effectiveStock > 0 && $effectiveStock < $item['quantity']) {
                $errors[] = sprintf(
                    'Insufficient stock for %s: requested %d, available %d',
                    $product->sku . ($variant !== null ? '-' . $variant->skuSuffix : ''),
                    $item['quantity'],
                    $effectiveStock,
                );

                continue;
            }

            if ($effectivePrice !== $item['unitPrice']) {
                $errors[] = sprintf(
                    'Price changed for %s: expected %d, current %d',
                    $product->sku . ($variant !== null ? '-' . $variant->skuSuffix : ''),
                    $item['unitPrice'],
                    $effectivePrice,
                );

                continue;
            }

            $validatedItems[] = [
                'productId' => $product->id,
                'variantId' => $variantId,
                'quantity' => $item['quantity'],
                'unitPrice' => $effectivePrice,
                'currency' => $product->priceCurrency,
            ];
        }

        return new CartValidationResult(
            isValid: $errors === [],
            errors: $errors,
            validatedItems: $validatedItems,
        );
    }

    private function confirmOrder(Order $order, ?string $paymentIntentId = null): void
    {
        OrderStatusStateMachine::transition($order->status, OrderStatus::Confirmed);

        // Update order status and payment info
        $this->db->execute(
            <<<'SQL'
                UPDATE cms_orders
                SET status = :status, payment_status = :payment_status,
                    payment_intent_id = :payment_intent_id, updated_at = :now
                WHERE id = :id
                SQL,
            [
                'status' => OrderStatus::Confirmed->value,
                'payment_status' => PaymentStatus::Paid->value,
                'payment_intent_id' => $paymentIntentId,
                'now' => new DateTimeImmutable()->format('c'),
                'id' => $order->id,
            ],
        );

        // Generate invoice
        $this->invoiceService->generate($order->id);

        // Create digital download tokens for digital products
        $this->digitalDelivery->createDownloadTokens($order->id);

        $this->events->dispatch(new PaymentReceivedEvent($order, $paymentIntentId));
        $this->events->dispatch(new OrderStatusChangedEvent($order, OrderStatus::Confirmed));
    }

    private function generateOrderNumber(ConnectionInterface $db, ?string $tenantId): string
    {
        $effectiveTenant = $tenantId ?? '__global__';

        $db->execute(
            <<<'SQL'
                INSERT INTO cms_order_sequences (tenant_id, last_number)
                VALUES (:tenant_id, 1)
                ON CONFLICT (tenant_id) DO UPDATE SET last_number = cms_order_sequences.last_number + 1
                SQL,
            ['tenant_id' => $effectiveTenant],
        );

        $result = $db->query(
            'SELECT last_number FROM cms_order_sequences WHERE tenant_id = :tenant_id',
            ['tenant_id' => $effectiveTenant],
        );

        $number = $result->firstOrFail()->getInt('last_number');

        return sprintf('ORD-%06d', $number);
    }

}
