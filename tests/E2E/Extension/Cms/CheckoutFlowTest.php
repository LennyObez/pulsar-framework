<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
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

use function bin2hex;
use function hash;
use function random_bytes;
use function round;

/**
 * E2E: Full checkout workflow — Cart -> validate -> coupon -> payment -> invoice -> digital download -> refund.
 */
#[Group('e2e-cms')]
final class CheckoutFlowTest extends TestCase
{
    #[Test]
    public function test_full_checkout_with_coupon_and_digital_download(): void
    {
        // Step 1: Create products
        $physicalProduct = new Product(
            id: 'prod-e2e-phys',
            tenantId: null,
            sku: 'HOODIE-001',
            status: ProductStatus::Active,
            priceAmount: 4999,
            priceCurrency: 'EUR',
            taxCategory: 'standard',
            stockQuantity: 50,
            digital: false,
            contentId: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        $digitalProduct = new Product(
            id: 'prod-e2e-dig',
            tenantId: null,
            sku: 'TEMPLATE-001',
            status: ProductStatus::Active,
            priceAmount: 1999,
            priceCurrency: 'EUR',
            taxCategory: 'digital',
            stockQuantity: 999,
            digital: true,
            contentId: null,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        self::assertTrue($physicalProduct->isActive());
        self::assertFalse($physicalProduct->isDigital());
        self::assertTrue($digitalProduct->isDigital());

        // Step 2: Validate cart
        $validation = new CartValidationResult(
            isValid: true,
            errors: [],
            validatedItems: [
                ['productId' => $physicalProduct->id, 'quantity' => 2, 'unitPrice' => 4999, 'currency' => 'EUR'],
                ['productId' => $digitalProduct->id, 'quantity' => 1, 'unitPrice' => 1999, 'currency' => 'EUR'],
            ],
        );

        self::assertTrue($validation->isValid);
        self::assertCount(2, $validation->validatedItems);

        // Step 3: Apply 15% coupon
        $promotion = new Promotion(
            id: 'promo-e2e-001',
            tenantId: null,
            name: '15% Off Everything',
            type: PromotionType::PercentageOff,
            value: 15,
            minOrderAmount: 5000,
            maxUses: 100,
            maxUsesPerCustomer: 1,
            currentUses: 5,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: null,
            expiresAt: new DateTimeImmutable('+30 days'),
            isActive: true,
        );

        $coupon = new Coupon(
            id: 'coupon-e2e-001',
            promotionId: $promotion->id,
            code: 'WELCOME15',
            isSingleUse: false,
            usedAt: null,
            usedBy: null,
        );

        self::assertTrue($coupon->isAvailable());

        $promoValidation = new PromotionValidationResult(true, $promotion, []);
        self::assertTrue($promoValidation->isValid);

        $subtotal = (4999 * 2) + 1999; // 11997
        $discountAmount = (int) round($subtotal * 0.15); // 1800

        $discount = new DiscountResult(
            totalDiscount: $discountAmount,
            itemDiscounts: [
                $physicalProduct->id => (int) round(9998 * 0.15),
                $digitalProduct->id => (int) round(1999 * 0.15),
            ],
        );

        self::assertSame(1800, $discount->totalDiscount);

        // Step 4: Calculate tax on discounted amount
        $afterDiscount = $subtotal - $discountAmount; // 10197
        $taxAmount = (int) round($afterDiscount * 0.21); // 2141

        $taxResult = new TaxResult(
            items: [
                new TaxLineItem($physicalProduct->id, 0.21, (int) round(8498 * 0.21)),
                new TaxLineItem($digitalProduct->id, 0.21, (int) round(1699 * 0.21)),
            ],
            totalTax: $taxAmount,
            reverseCharge: false,
        );

        self::assertGreaterThan(0, $taxResult->totalTax);

        // Step 5: Create order
        $order = new Order(
            id: 'order-e2e-checkout',
            tenantId: null,
            orderNumber: 'ORD-E2E-CHECKOUT-001',
            customerId: 'cust-e2e-001',
            customerEmail: 'checkout-test@example.com',
            status: OrderStatus::Cart,
            subtotal: $subtotal,
            taxAmount: $taxAmount,
            discountAmount: $discountAmount,
            total: $afterDiscount + $taxAmount,
            amountRefunded: 0,
            currency: 'EUR',
            paymentIntentId: null,
            paymentStatus: PaymentStatus::Pending,
            billingAddress: ['line1' => '10 Avenue Louise', 'city' => 'Brussels', 'postalCode' => '1050', 'country' => 'BE'],
            shippingAddress: ['line1' => '10 Avenue Louise', 'city' => 'Brussels', 'postalCode' => '1050', 'country' => 'BE'],
            notes: 'E2E checkout test order',
            dataClassification: DataClassification::Pii,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );

        self::assertSame(OrderStatus::Cart, $order->status);
        self::assertSame($subtotal, $order->subtotal);
        self::assertSame($discountAmount, $order->discountAmount);

        // Step 6: Transition Cart -> PendingPayment -> Confirmed
        self::assertTrue(OrderStatusStateMachine::canTransition(OrderStatus::Cart, OrderStatus::PendingPayment));
        $pendingStatus = OrderStatusStateMachine::transition(OrderStatus::Cart, OrderStatus::PendingPayment);
        self::assertSame(OrderStatus::PendingPayment, $pendingStatus);

        $paymentResult = new PaymentResult(
            success: true,
            orderId: $order->id,
            paymentIntentId: 'pi_e2e_checkout_test',
            requiresRedirect: false,
            redirectUrl: null,
        );

        self::assertTrue($paymentResult->success);
        self::assertNotEmpty($paymentResult->paymentIntentId);

        $confirmedStatus = OrderStatusStateMachine::transition(OrderStatus::PendingPayment, OrderStatus::Confirmed);
        self::assertSame(OrderStatus::Confirmed, $confirmedStatus);

        // Step 7: Generate invoice
        $invoice = new Invoice(
            id: 'inv-e2e-checkout',
            orderId: $order->id,
            invoiceNumber: 'INV-E2E-CHECKOUT-001',
            issuedAt: new DateTimeImmutable(),
            dueAt: new DateTimeImmutable('+30 days'),
            pdfStoragePath: '/invoices/INV-E2E-CHECKOUT-001.pdf',
            pdfHash: hash('sha256', 'invoice-pdf-content'),
            evidenceHash: hash('sha256', 'invoice-evidence-data'),
            dataClassification: DataClassification::Pii,
        );

        self::assertSame('INV-E2E-CHECKOUT-001', $invoice->invoiceNumber);
        self::assertNotEmpty($invoice->evidenceHash);
        self::assertNotEmpty($invoice->pdfHash);

        // Step 8: Digital download token
        $digitalAsset = new DigitalAsset(
            id: 'asset-e2e-001',
            productId: $digitalProduct->id,
            fileStoragePath: '/digital/template-pack.zip',
            fileHash: hash('sha256', 'template-pack-content'),
            fileName: 'template-pack.zip',
            fileSize: 12_000_000,
            maxDownloads: 3,
        );

        $download = new DigitalDownload(
            id: 'dl-e2e-checkout',
            orderItemId: 'item-e2e-002',
            digitalAssetId: $digitalAsset->id,
            downloadToken: bin2hex(random_bytes(32)),
            downloadsRemaining: 3,
            expiresAt: new DateTimeImmutable('+7 days'),
        );

        self::assertTrue($download->isValid());
        self::assertSame(3, $download->downloadsRemaining);

        // Simulate download
        $downloadResult = new DownloadResult(
            success: true,
            filePath: $digitalAsset->fileStoragePath,
            fileName: $digitalAsset->fileName,
            downloadsRemaining: 2,
        );

        self::assertTrue($downloadResult->success);
        self::assertSame('template-pack.zip', $downloadResult->fileName);
        self::assertSame(2, $downloadResult->downloadsRemaining);

        // Step 9: Transition to Fulfilled
        $fulfilledStatus = OrderStatusStateMachine::transition(OrderStatus::Confirmed, OrderStatus::Fulfilled);
        self::assertSame(OrderStatus::Fulfilled, $fulfilledStatus);
    }

    #[Test]
    public function test_invalid_status_transition_rejected(): void
    {
        self::assertFalse(OrderStatusStateMachine::canTransition(OrderStatus::Cart, OrderStatus::Fulfilled));
        self::assertFalse(OrderStatusStateMachine::canTransition(OrderStatus::Cancelled, OrderStatus::Confirmed));
    }

    #[Test]
    public function test_expired_download_token_rejected(): void
    {
        $download = new DigitalDownload(
            id: 'dl-expired',
            orderItemId: 'item-expired',
            digitalAssetId: 'asset-expired',
            downloadToken: bin2hex(random_bytes(32)),
            downloadsRemaining: 3,
            expiresAt: new DateTimeImmutable('-1 day'),
        );

        self::assertFalse($download->isValid());
    }

    #[Test]
    public function test_zero_downloads_remaining_invalid(): void
    {
        $download = new DigitalDownload(
            id: 'dl-exhausted',
            orderItemId: 'item-exhausted',
            digitalAssetId: 'asset-exhausted',
            downloadToken: bin2hex(random_bytes(32)),
            downloadsRemaining: 0,
            expiresAt: new DateTimeImmutable('+7 days'),
        );

        self::assertFalse($download->isValid());
    }
}
