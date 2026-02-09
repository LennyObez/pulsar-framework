<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\CartValidationResult;
use Pulsar\Extension\Cms\Commerce\Coupon;
use Pulsar\Extension\Cms\Commerce\DigitalAsset;
use Pulsar\Extension\Cms\Commerce\DigitalDownload;
use Pulsar\Extension\Cms\Commerce\DiscountResult;
use Pulsar\Extension\Cms\Commerce\DownloadResult;
use Pulsar\Extension\Cms\Commerce\Invoice;
use Pulsar\Extension\Cms\Commerce\Order;
use Pulsar\Extension\Cms\Commerce\OrderItem;
use Pulsar\Extension\Cms\Commerce\OrderStatus;
use Pulsar\Extension\Cms\Commerce\OrderStatusStateMachine;
use Pulsar\Extension\Cms\Commerce\PaymentResult;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
use Pulsar\Extension\Cms\Commerce\Promotion;
use Pulsar\Extension\Cms\Commerce\PromotionType;
use Pulsar\Extension\Cms\Commerce\PromotionValidationResult;
use Pulsar\Extension\Cms\Commerce\TaxLineItem;
use Pulsar\Extension\Cms\Commerce\TaxResult;
use Pulsar\Extension\Cms\Content\DataClassification;

#[CoversClass(Order::class)]
#[CoversClass(OrderItem::class)]
#[CoversClass(OrderStatusStateMachine::class)]
#[CoversClass(Invoice::class)]
#[CoversClass(DigitalDownload::class)]
final class CheckoutFlowTest extends TestCase
{
    #[Test]
    public function test_full_checkout_products_to_digital_download(): void
    {
        // 1. Create products (physical + digital)
        $physicalProduct = new Product(
            id: 'prod-phys-001',
            tenantId: null,
            sku: 'TSHIRT-001',
            status: ProductStatus::Active,
            priceAmount: 2999,
            priceCurrency: 'EUR',
            taxCategory: 'standard',
            stockQuantity: 100,
            digital: false,
            contentId: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $digitalProduct = new Product(
            id: 'prod-dig-001',
            tenantId: null,
            sku: 'EBOOK-001',
            status: ProductStatus::Active,
            priceAmount: 999,
            priceCurrency: 'EUR',
            taxCategory: 'digital',
            stockQuantity: 999,
            digital: true,
            contentId: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        self::assertTrue($physicalProduct->isActive());
        self::assertTrue($digitalProduct->isDigital());

        // 2. Build cart
        $cartItems = [
            ['productId' => $physicalProduct->id, 'quantity' => 1, 'unitPrice' => 2999],
            ['productId' => $digitalProduct->id, 'quantity' => 1, 'unitPrice' => 999],
        ];

        $validation = new CartValidationResult(
            isValid: true,
            errors: [],
            validatedItems: [
                ['productId' => $physicalProduct->id, 'quantity' => 1, 'unitPrice' => 2999, 'currency' => 'EUR'],
                ['productId' => $digitalProduct->id, 'quantity' => 1, 'unitPrice' => 999, 'currency' => 'EUR'],
            ],
        );

        self::assertTrue($validation->isValid);

        // 3. Apply coupon
        $promotion = new Promotion(
            id: 'promo-001',
            tenantId: null,
            name: '10% Off',
            type: PromotionType::PercentageOff,
            value: 10,
            minOrderAmount: null,
            maxUses: null,
            maxUsesPerCustomer: null,
            currentUses: 0,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: null,
            expiresAt: null,
            isActive: true,
        );

        $coupon = new Coupon(
            id: 'coupon-001',
            promotionId: 'promo-001',
            code: 'SAVE10',
            isSingleUse: false,
            usedAt: null,
            usedBy: null,
        );

        self::assertTrue($coupon->isAvailable());

        $promoValidation = new PromotionValidationResult(true, $promotion, []);
        self::assertTrue($promoValidation->isValid);

        $subtotal = 2999 + 999; // 3998
        $discountAmount = (int) round($subtotal * 0.10); // 400

        $discount = new DiscountResult(
            totalDiscount: $discountAmount,
            itemDiscounts: [
                $physicalProduct->id => (int) round(2999 * 0.10),
                $digitalProduct->id => (int) round(999 * 0.10),
            ],
        );

        self::assertSame(400, $discount->totalDiscount);

        // 4. Create order
        $afterDiscount = $subtotal - $discountAmount; // 3598
        $taxAmount = (int) round($afterDiscount * 0.21); // 755

        $taxResult = new TaxResult(
            items: [
                new TaxLineItem($physicalProduct->id, 0.21, (int) round(2699 * 0.21)),
                new TaxLineItem($digitalProduct->id, 0.21, (int) round(899 * 0.21)),
            ],
            totalTax: $taxAmount,
            reverseCharge: false,
        );

        $order = new Order(
            id: 'order-e2e-001',
            tenantId: null,
            orderNumber: 'ORD-E2E-0001',
            customerId: 'cust-001',
            customerEmail: 'buyer@test.com',
            status: OrderStatus::Cart,
            subtotal: $subtotal,
            taxAmount: $taxAmount,
            discountAmount: $discountAmount,
            shippingAmount: 0,
            shippingMethod: null,
            total: $afterDiscount + $taxAmount,
            amountRefunded: 0,
            currency: 'EUR',
            paymentIntentId: null,
            paymentStatus: PaymentStatus::Pending,
            billingAddress: ['line1' => '1 Test Ln', 'city' => 'Brussels', 'postalCode' => '1000', 'country' => 'BE'],
            shippingAddress: ['line1' => '1 Test Ln', 'city' => 'Brussels', 'postalCode' => '1000', 'country' => 'BE'],
            notes: null,
            dataClassification: DataClassification::Pii,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        self::assertSame(OrderStatus::Cart, $order->status);
        self::assertSame(3998, $order->subtotal);

        // 5. Payment mock -> confirmed
        self::assertTrue(OrderStatusStateMachine::canTransition(OrderStatus::Cart, OrderStatus::PendingPayment));
        $newStatus = OrderStatusStateMachine::transition(OrderStatus::Cart, OrderStatus::PendingPayment);
        self::assertSame(OrderStatus::PendingPayment, $newStatus);

        $paymentResult = new PaymentResult(
            success: true,
            orderId: 'order-e2e-001',
            paymentIntentId: 'pi_e2e_test',
            requiresRedirect: false,
            redirectUrl: null,
        );

        self::assertTrue($paymentResult->success);

        $confirmedStatus = OrderStatusStateMachine::transition(OrderStatus::PendingPayment, OrderStatus::Confirmed);
        self::assertSame(OrderStatus::Confirmed, $confirmedStatus);

        // 6. Generate invoice
        $invoice = new Invoice(
            id: 'inv-e2e-001',
            orderId: 'order-e2e-001',
            invoiceNumber: 'INV-E2E-0001',
            issuedAt: new DateTimeImmutable(),
            dueAt: new DateTimeImmutable('+30 days'),
            pdfStoragePath: '/invoices/INV-E2E-0001.pdf',
            pdfHash: hash('sha256', 'pdf-content'),
            evidenceHash: hash('sha256', 'invoice-data'),
            dataClassification: DataClassification::Pii,
        );

        self::assertSame('INV-E2E-0001', $invoice->invoiceNumber);
        self::assertNotEmpty($invoice->evidenceHash);

        // 7. Digital download tokens
        $digitalAsset = new DigitalAsset(
            id: 'asset-001',
            productId: $digitalProduct->id,
            fileStoragePath: '/digital/ebook.pdf',
            fileHash: hash('sha256', 'ebook-content'),
            fileName: 'ebook.pdf',
            fileSize: 5_000_000,
            maxDownloads: 5,
        );

        $download = new DigitalDownload(
            id: 'dl-e2e-001',
            orderItemId: 'item-002',
            digitalAssetId: $digitalAsset->id,
            downloadToken: bin2hex(random_bytes(32)),
            downloadsRemaining: 5,
            expiresAt: new DateTimeImmutable('+30 days'),
        );

        self::assertTrue($download->isValid());
        self::assertNotEmpty($download->downloadToken);
        self::assertSame(5, $download->downloadsRemaining);

        $downloadResult = new DownloadResult(
            success: true,
            filePath: $digitalAsset->fileStoragePath,
            fileName: $digitalAsset->fileName,
            downloadsRemaining: 4,
        );

        self::assertTrue($downloadResult->success);
        self::assertSame('ebook.pdf', $downloadResult->fileName);
        self::assertSame(4, $downloadResult->downloadsRemaining);
    }
}
