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
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionServiceInterface;
use Pulsar\Extension\Cms\Commerce\TaxCalculatorInterface;
use Pulsar\Extension\Cms\Content\DataClassification;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function array_map;
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

            if ($product->stockQuantity > 0 && $product->stockQuantity < $item['quantity']) {
                $errors[] = sprintf(
                    'Insufficient stock for %s: requested %d, available %d',
                    $product->sku,
                    $item['quantity'],
                    $product->stockQuantity,
                );

                continue;
            }

            if ($product->priceAmount !== $item['unitPrice']) {
                $errors[] = sprintf(
                    'Price changed for %s: expected %d, current %d',
                    $product->sku,
                    $item['unitPrice'],
                    $product->priceAmount,
                );

                continue;
            }

            $validatedItems[] = [
                'productId' => $product->id,
                'quantity' => $item['quantity'],
                'unitPrice' => $product->priceAmount,
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
            // Validate cart within transaction
            $validation = $this->validateCart($cartItems);

            if (!$validation->isValid) {
                throw new CmsException('Cart validation failed: ' . implode('; ', $validation->errors));
            }

            // Reserve stock atomically
            foreach ($validation->validatedItems as $item) {
                $product = $this->products->findById($item['productId']);

                if ($product !== null && $product->stockQuantity > 0) {
                    $reserved = $db->execute(
                        'UPDATE cms_products SET stock_quantity = stock_quantity - :qty, updated_at = :now WHERE id = :id AND stock_quantity >= :qty',
                        [
                            'qty' => $item['quantity'],
                            'now' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                            'id' => $item['productId'],
                        ],
                    );

                    if ($reserved === 0) {
                        throw new CmsException(sprintf('Failed to reserve stock for product: %s', $item['productId']));
                    }
                }
            }

            // Generate order number
            $orderNumber = $this->generateOrderNumber($db, $tenantId);
            $orderId = $this->generateUuidV7();
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

                $product = $this->products->findById($item['productId']);
                $snapshot = $product !== null ? [
                    'sku' => $product->sku,
                    'priceAmount' => $product->priceAmount,
                    'priceCurrency' => $product->priceCurrency,
                    'digital' => $product->digital,
                    'taxCategory' => $product->taxCategory,
                ] : [];

                $orderItem = new OrderItem(
                    id: $this->generateUuidV7(),
                    orderId: $orderId,
                    productId: $item['productId'],
                    variantId: null,
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

        // Restore reserved stock
        $items = $this->orderItems->findByOrder($orderId);

        foreach ($items as $item) {
            $this->db->execute(
                'UPDATE cms_products SET stock_quantity = stock_quantity + :qty, updated_at = :now WHERE id = :id',
                [
                    'qty' => $item->quantity,
                    'now' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
                    'id' => $item->productId,
                ],
            );
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
                'now' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
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
        $sql = 'SELECT COUNT(*) AS cnt FROM cms_orders';
        $bindings = [];

        if ($tenantId !== null) {
            $sql .= ' WHERE tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        }

        $count = $db->query($sql, $bindings)->first()?->getInt('cnt') ?? 0;

        return sprintf('ORD-%06d', $count + 1);
    }

    private function generateUuidV7(): string
    {
        $time = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12),
        );
    }
}
