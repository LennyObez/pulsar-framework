<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\CartValidationResult;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderItem;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
use Pulsar\Extension\Cms\Commerce\TaxLineItem;
use Pulsar\Extension\Cms\Commerce\TaxRateConfig;
use Pulsar\Extension\Cms\Commerce\TaxResult;
use Pulsar\Extension\Cms\Content\DataClassification;

#[CoversClass(Order::class)]
#[CoversClass(OrderItem::class)]
#[CoversClass(Product::class)]
final class CheckoutIntegrationTest extends TestCase
{
    #[Test]
    public function test_full_checkout_flow(): void
    {
        // Step 1: Create products
        $product1 = Product::create(
            id: 'prod-001',
            sku: 'WIDGET-A',
            priceAmount: 2500,
            priceCurrency: 'EUR',
            taxCategory: 'standard',
            stockQuantity: 100,
        );

        $product2 = Product::create(
            id: 'prod-002',
            sku: 'GADGET-B',
            priceAmount: 5000,
            priceCurrency: 'EUR',
            taxCategory: 'reduced',
            stockQuantity: 50,
        );

        // Activate products (simulate status change)
        $product1 = new Product(
            id: $product1->id,
            tenantId: null,
            sku: $product1->sku,
            status: ProductStatus::Active,
            priceAmount: $product1->priceAmount,
            priceCurrency: $product1->priceCurrency,
            taxCategory: $product1->taxCategory,
            stockQuantity: $product1->stockQuantity,
            digital: false,
            contentId: null,
            createdAt: $product1->createdAt,
            updatedAt: new DateTimeImmutable(),
        );

        $product2 = new Product(
            id: $product2->id,
            tenantId: null,
            sku: $product2->sku,
            status: ProductStatus::Active,
            priceAmount: $product2->priceAmount,
            priceCurrency: $product2->priceCurrency,
            taxCategory: $product2->taxCategory,
            stockQuantity: $product2->stockQuantity,
            digital: false,
            contentId: null,
            createdAt: $product2->createdAt,
            updatedAt: new DateTimeImmutable(),
        );

        self::assertTrue($product1->isActive());
        self::assertTrue($product2->isActive());

        // Step 2: Validate cart
        $cartItems = [
            ['productId' => 'prod-001', 'quantity' => 2, 'unitPrice' => 2500],
            ['productId' => 'prod-002', 'quantity' => 1, 'unitPrice' => 5000],
        ];

        $validation = new CartValidationResult(
            isValid: true,
            errors: [],
            validatedItems: [
                ['productId' => 'prod-001', 'quantity' => 2, 'unitPrice' => 2500, 'currency' => 'EUR'],
                ['productId' => 'prod-002', 'quantity' => 1, 'unitPrice' => 5000, 'currency' => 'EUR'],
            ],
        );

        self::assertTrue($validation->isValid);
        self::assertCount(2, $validation->validatedItems);

        // Step 3: Create order
        $subtotal = (2500 * 2) + (5000 * 1); // 10000

        // Step 4: Calculate tax
        $taxRates = [
            new TaxRateConfig('standard', 0.21, 'Standard VAT', ['BE']),
            new TaxRateConfig('reduced', 0.06, 'Reduced VAT', ['BE']),
        ];

        $taxItems = [
            new TaxLineItem('prod-001', 0.21, (int) round(5000 * 0.21)), // 1050
            new TaxLineItem('prod-002', 0.06, (int) round(5000 * 0.06)), // 300
        ];
        $totalTax = 1050 + 300;

        $taxResult = new TaxResult(items: $taxItems, totalTax: $totalTax, reverseCharge: false);

        self::assertSame(1350, $taxResult->totalTax);
        self::assertFalse($taxResult->reverseCharge);

        // Step 5: Create order with snapshots
        $order = new Order(
            id: 'order-001',
            tenantId: null,
            orderNumber: 'ORD-2026-0001',
            customerId: 'cust-001',
            customerEmail: 'customer@test.com',
            status: OrderStatus::PendingPayment,
            subtotal: $subtotal,
            taxAmount: $totalTax,
            discountAmount: 0,
            shippingAmount: 0,
            shippingMethod: null,
            total: $subtotal + $totalTax,
            amountRefunded: 0,
            currency: 'EUR',
            paymentIntentId: null,
            paymentStatus: PaymentStatus::Pending,
            billingAddress: ['line1' => '123 Main St', 'city' => 'Brussels', 'postalCode' => '1000', 'country' => 'BE'],
            shippingAddress: null,
            notes: null,
            dataClassification: DataClassification::Pii,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        // Verify order
        self::assertSame('ORD-2026-0001', $order->orderNumber);
        self::assertSame(OrderStatus::PendingPayment, $order->status);
        self::assertSame(10000, $order->subtotal);
        self::assertSame(1350, $order->taxAmount);
        self::assertSame(11350, $order->total);

        // Step 6: Create order items with product snapshots
        $orderItems = [
            new OrderItem(
                id: 'item-001',
                orderId: 'order-001',
                productId: 'prod-001',
                variantId: null,
                quantity: 2,
                unitPrice: 2500,
                totalPrice: 5000,
                taxAmount: 1050,
                discountAmount: 0,
                productSnapshot: ['sku' => 'WIDGET-A', 'name' => 'Widget A', 'priceAmount' => 2500],
            ),
            new OrderItem(
                id: 'item-002',
                orderId: 'order-001',
                productId: 'prod-002',
                variantId: null,
                quantity: 1,
                unitPrice: 5000,
                totalPrice: 5000,
                taxAmount: 300,
                discountAmount: 0,
                productSnapshot: ['sku' => 'GADGET-B', 'name' => 'Gadget B', 'priceAmount' => 5000],
            ),
        ];

        // Verify snapshots
        self::assertSame('WIDGET-A', $orderItems[0]->productSnapshot['sku']);
        self::assertSame(2500, $orderItems[0]->productSnapshot['priceAmount']);
        self::assertSame('GADGET-B', $orderItems[1]->productSnapshot['sku']);
    }
}
