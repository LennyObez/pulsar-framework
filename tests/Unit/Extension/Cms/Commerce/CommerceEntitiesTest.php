<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\ApiKey;
use Pulsar\Extension\Cms\Commerce\CartValidationResult;
use Pulsar\Extension\Cms\Commerce\Coupon;
use Pulsar\Extension\Cms\Commerce\Customer;
use Pulsar\Extension\Cms\Commerce\DigitalAsset;
use Pulsar\Extension\Cms\Commerce\DigitalDownload;
use Pulsar\Extension\Cms\Commerce\DiscountResult;
use Pulsar\Extension\Cms\Commerce\DownloadResult;
use Pulsar\Extension\Cms\Commerce\Invoice;
use Pulsar\Extension\Cms\Commerce\OrderItem;
use Pulsar\Extension\Cms\Commerce\PaymentResult;
use Pulsar\Extension\Cms\Commerce\PaymentStatus;
use Pulsar\Extension\Cms\Commerce\ProductAttribute;
use Pulsar\Extension\Cms\Commerce\ProductTranslation;
use Pulsar\Extension\Cms\Commerce\PromotionType;
use Pulsar\Extension\Cms\Commerce\PromotionValidationResult;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;
use Pulsar\Extension\Cms\Commerce\ShippingResult;
use Pulsar\Extension\Cms\Commerce\TaxLineItem;
use Pulsar\Extension\Cms\Commerce\TaxResult;
use Pulsar\Extension\Cms\Content\DataClassification;

#[CoversClass(ApiKey::class)]
#[CoversClass(CartValidationResult::class)]
#[CoversClass(Coupon::class)]
#[CoversClass(Customer::class)]
#[CoversClass(DigitalAsset::class)]
#[CoversClass(DigitalDownload::class)]
#[CoversClass(DiscountResult::class)]
#[CoversClass(DownloadResult::class)]
#[CoversClass(Invoice::class)]
#[CoversClass(OrderItem::class)]
#[CoversClass(PaymentResult::class)]
#[CoversClass(PaymentStatus::class)]
#[CoversClass(ProductAttribute::class)]
#[CoversClass(ProductTranslation::class)]
#[CoversClass(PromotionType::class)]
#[CoversClass(PromotionValidationResult::class)]
#[CoversClass(ShippingResult::class)]
#[CoversClass(TaxLineItem::class)]
#[CoversClass(TaxResult::class)]
final class CommerceEntitiesTest extends TestCase
{
    // -- ApiKey ----------------------------------------------------------------

    #[Test]
    public function apiKeyIsNotExpiredWhenNoExpiry(): void
    {
        $key = new ApiKey(
            id: '0194d4e0-aaaa-7000-bbbb-000000000001',
            tenantId: null,
            name: 'Production API Key',
            keyHash: hash('sha256', 'sk_live_abc123'),
            lastUsedAt: null,
            isActive: true,
            createdAt: new DateTimeImmutable('2025-01-15T10:00:00+00:00'),
            expiresAt: null,
        );

        self::assertFalse($key->isExpired());
        self::assertTrue($key->isActive);
        self::assertNull($key->tenantId);
        self::assertNull($key->lastUsedAt);
    }

    #[Test]
    public function apiKeyIsExpiredWhenPastExpiry(): void
    {
        $key = new ApiKey(
            id: '0194d4e0-aaaa-7000-bbbb-000000000002',
            tenantId: 'tenant-abc',
            name: 'Expired Key',
            keyHash: hash('sha256', 'sk_test_expired'),
            lastUsedAt: '2025-01-10T08:00:00+00:00',
            isActive: false,
            createdAt: new DateTimeImmutable('2024-06-01T00:00:00+00:00'),
            expiresAt: new DateTimeImmutable('2024-12-31T23:59:59+00:00'),
        );

        self::assertTrue($key->isExpired());
        self::assertFalse($key->isActive);
        self::assertSame('tenant-abc', $key->tenantId);
    }

    #[Test]
    public function apiKeyIsNotExpiredWhenFutureExpiry(): void
    {
        $key = new ApiKey(
            id: '0194d4e0-aaaa-7000-bbbb-000000000003',
            tenantId: null,
            name: 'Future Key',
            keyHash: hash('sha256', 'sk_live_future'),
            lastUsedAt: null,
            isActive: true,
            createdAt: new DateTimeImmutable('2025-01-01T00:00:00+00:00'),
            expiresAt: new DateTimeImmutable('2099-12-31T23:59:59+00:00'),
        );

        self::assertFalse($key->isExpired());
    }

    // -- CartValidationResult -------------------------------------------------

    #[Test]
    public function cartValidationResultValidCase(): void
    {
        $result = new CartValidationResult(
            isValid: true,
            errors: [],
            validatedItems: [
                ['productId' => 'prod-01', 'quantity' => 2, 'unitPrice' => 1999, 'currency' => 'EUR'],
            ],
        );

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
        self::assertCount(1, $result->validatedItems);
        self::assertSame('prod-01', $result->validatedItems[0]['productId']);
    }

    #[Test]
    public function cartValidationResultWithErrors(): void
    {
        $result = new CartValidationResult(
            isValid: false,
            errors: ['Product not found', 'Insufficient stock'],
            validatedItems: [],
        );

        self::assertFalse($result->isValid);
        self::assertCount(2, $result->errors);
    }

    // -- Coupon ---------------------------------------------------------------

    #[Test]
    public function couponIsAvailableWhenNotSingleUse(): void
    {
        $coupon = new Coupon(
            id: '0194d4e0-cccc-7000-dddd-000000000001',
            promotionId: '0194d4e0-cccc-7000-dddd-000000000002',
            code: 'SUMMER2025',
            isSingleUse: false,
            usedAt: new DateTimeImmutable(),
            usedBy: 'cust-01',
        );

        self::assertTrue($coupon->isAvailable());
    }

    #[Test]
    public function couponSingleUseIsAvailableWhenUnused(): void
    {
        $coupon = new Coupon(
            id: '0194d4e0-cccc-7000-dddd-000000000003',
            promotionId: '0194d4e0-cccc-7000-dddd-000000000004',
            code: 'WELCOME10',
            isSingleUse: true,
            usedAt: null,
            usedBy: null,
        );

        self::assertTrue($coupon->isAvailable());
    }

    #[Test]
    public function couponSingleUseIsNotAvailableWhenUsed(): void
    {
        $coupon = new Coupon(
            id: '0194d4e0-cccc-7000-dddd-000000000005',
            promotionId: '0194d4e0-cccc-7000-dddd-000000000006',
            code: 'ONETIMEONLY',
            isSingleUse: true,
            usedAt: new DateTimeImmutable('2025-03-01T12:00:00+00:00'),
            usedBy: 'cust-02',
        );

        self::assertFalse($coupon->isAvailable());
    }

    // -- Customer -------------------------------------------------------------

    #[Test]
    public function customerHasLinkedUserWhenUserIdPresent(): void
    {
        $now = new DateTimeImmutable();
        $customer = new Customer(
            id: '0194d4e0-eeee-7000-ffff-000000000001',
            tenantId: 'tenant-01',
            userId: 'user-42',
            email: 'elena.martinez@example.com',
            displayName: 'Elena Martinez',
            billingAddress: ['line1' => '742 Evergreen Terrace', 'city' => 'Springfield', 'postalCode' => '62704', 'country' => 'US'],
            shippingAddress: null,
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertTrue($customer->hasLinkedUser());
        self::assertSame('user-42', $customer->userId);
        self::assertSame('elena.martinez@example.com', $customer->email);
    }

    #[Test]
    public function customerHasNoLinkedUserWhenUserIdNull(): void
    {
        $now = new DateTimeImmutable();
        $customer = new Customer(
            id: '0194d4e0-eeee-7000-ffff-000000000002',
            tenantId: null,
            userId: null,
            email: 'guest@example.com',
            displayName: null,
            billingAddress: null,
            shippingAddress: null,
            createdAt: $now,
            updatedAt: $now,
        );

        self::assertFalse($customer->hasLinkedUser());
        self::assertNull($customer->displayName);
    }

    // -- DigitalAsset ---------------------------------------------------------

    #[Test]
    public function digitalAssetConstructor(): void
    {
        $asset = new DigitalAsset(
            id: '0194d4e0-1111-7000-2222-000000000001',
            productId: '0194d4e0-1111-7000-2222-000000000002',
            fileStoragePath: '/digital/products/ebook-2025.pdf',
            fileHash: hash('sha256', 'ebook-content'),
            fileName: 'advanced-php-patterns.pdf',
            fileSize: 5_242_880,
            maxDownloads: 3,
        );

        self::assertSame('/digital/products/ebook-2025.pdf', $asset->fileStoragePath);
        self::assertSame('advanced-php-patterns.pdf', $asset->fileName);
        self::assertSame(5_242_880, $asset->fileSize);
        self::assertSame(3, $asset->maxDownloads);
    }

    // -- DigitalDownload ------------------------------------------------------

    #[Test]
    public function digitalDownloadIsValidWhenDownloadsRemainingAndNotExpired(): void
    {
        $download = new DigitalDownload(
            id: '0194d4e0-3333-7000-4444-000000000001',
            orderItemId: '0194d4e0-3333-7000-4444-000000000002',
            digitalAssetId: '0194d4e0-3333-7000-4444-000000000003',
            downloadToken: bin2hex(random_bytes(16)),
            downloadsRemaining: 2,
            expiresAt: new DateTimeImmutable('+30 days'),
        );

        self::assertTrue($download->isValid());
    }

    #[Test]
    public function digitalDownloadIsInvalidWhenNoDownloadsRemaining(): void
    {
        $download = new DigitalDownload(
            id: '0194d4e0-3333-7000-4444-000000000004',
            orderItemId: '0194d4e0-3333-7000-4444-000000000005',
            digitalAssetId: '0194d4e0-3333-7000-4444-000000000006',
            downloadToken: bin2hex(random_bytes(16)),
            downloadsRemaining: 0,
            expiresAt: new DateTimeImmutable('+30 days'),
        );

        self::assertFalse($download->isValid());
    }

    #[Test]
    public function digitalDownloadIsInvalidWhenExpired(): void
    {
        $download = new DigitalDownload(
            id: '0194d4e0-3333-7000-4444-000000000007',
            orderItemId: '0194d4e0-3333-7000-4444-000000000008',
            digitalAssetId: '0194d4e0-3333-7000-4444-000000000009',
            downloadToken: bin2hex(random_bytes(16)),
            downloadsRemaining: 5,
            expiresAt: new DateTimeImmutable('-1 day'),
        );

        self::assertFalse($download->isValid());
    }

    #[Test]
    public function digitalDownloadIsValidWithExplicitNowParameter(): void
    {
        $expiresAt = new DateTimeImmutable('2025-06-01T00:00:00+00:00');
        $download = new DigitalDownload(
            id: '0194d4e0-3333-7000-4444-000000000010',
            orderItemId: '0194d4e0-3333-7000-4444-000000000011',
            digitalAssetId: '0194d4e0-3333-7000-4444-000000000012',
            downloadToken: bin2hex(random_bytes(16)),
            downloadsRemaining: 1,
            expiresAt: $expiresAt,
        );

        $beforeExpiry = new DateTimeImmutable('2025-05-31T23:59:59+00:00');
        self::assertTrue($download->isValid($beforeExpiry));

        $afterExpiry = new DateTimeImmutable('2025-06-01T00:00:01+00:00');
        self::assertFalse($download->isValid($afterExpiry));
    }

    // -- DiscountResult -------------------------------------------------------

    #[Test]
    public function discountResultConstructor(): void
    {
        $result = new DiscountResult(
            totalDiscount: 2500,
            itemDiscounts: ['prod-01' => 1500, 'prod-02' => 1000],
        );

        self::assertSame(2500, $result->totalDiscount);
        self::assertSame(1500, $result->itemDiscounts['prod-01']);
        self::assertSame(1000, $result->itemDiscounts['prod-02']);
    }

    // -- DownloadResult -------------------------------------------------------

    #[Test]
    public function downloadResultSuccessful(): void
    {
        $result = new DownloadResult(
            success: true,
            filePath: '/storage/digital/ebook.pdf',
            fileName: 'ebook.pdf',
            downloadsRemaining: 4,
        );

        self::assertTrue($result->success);
        self::assertSame('/storage/digital/ebook.pdf', $result->filePath);
        self::assertSame(4, $result->downloadsRemaining);
    }

    #[Test]
    public function downloadResultFailed(): void
    {
        $result = new DownloadResult(
            success: false,
            filePath: null,
            fileName: null,
            downloadsRemaining: null,
        );

        self::assertFalse($result->success);
        self::assertNull($result->filePath);
    }

    // -- Invoice --------------------------------------------------------------

    #[Test]
    public function invoiceConstructor(): void
    {
        $now = new DateTimeImmutable();
        $due = new DateTimeImmutable('+30 days');

        $invoice = new Invoice(
            id: '0194d4e0-5555-7000-6666-000000000001',
            orderId: '0194d4e0-5555-7000-6666-000000000002',
            invoiceNumber: 'INV-2025-001234',
            issuedAt: $now,
            dueAt: $due,
            pdfStoragePath: '/invoices/2025/03/INV-2025-001234.pdf',
            pdfHash: hash('sha256', 'pdf-content'),
            evidenceHash: hash('sha256', 'evidence-data'),
            dataClassification: DataClassification::Pii,
        );

        self::assertSame('INV-2025-001234', $invoice->invoiceNumber);
        self::assertSame($now, $invoice->issuedAt);
        self::assertSame($due, $invoice->dueAt);
        self::assertNotNull($invoice->pdfStoragePath);
        self::assertSame(DataClassification::Pii, $invoice->dataClassification);
    }

    // -- OrderItem ------------------------------------------------------------

    #[Test]
    public function orderItemConstructor(): void
    {
        $item = new OrderItem(
            id: '0194d4e0-7777-7000-8888-000000000001',
            orderId: '0194d4e0-7777-7000-8888-000000000002',
            productId: '0194d4e0-7777-7000-8888-000000000003',
            variantId: '0194d4e0-7777-7000-8888-000000000004',
            quantity: 3,
            unitPrice: 2499,
            totalPrice: 7497,
            taxAmount: 1312,
            discountAmount: 500,
            productSnapshot: ['name' => 'Premium Widget', 'sku' => 'WDG-001'],
        );

        self::assertSame(3, $item->quantity);
        self::assertSame(2499, $item->unitPrice);
        self::assertSame(7497, $item->totalPrice);
        self::assertSame(1312, $item->taxAmount);
        self::assertSame(500, $item->discountAmount);
        self::assertSame('WDG-001', $item->productSnapshot['sku']);
    }

    #[Test]
    public function orderItemWithoutVariant(): void
    {
        $item = new OrderItem(
            id: '0194d4e0-7777-7000-8888-000000000005',
            orderId: '0194d4e0-7777-7000-8888-000000000006',
            productId: '0194d4e0-7777-7000-8888-000000000007',
            variantId: null,
            quantity: 1,
            unitPrice: 999,
            totalPrice: 999,
            taxAmount: 0,
            discountAmount: 0,
            productSnapshot: ['name' => 'Basic Widget'],
        );

        self::assertNull($item->variantId);
    }

    // -- PaymentResult --------------------------------------------------------

    #[Test]
    public function paymentResultSuccessful(): void
    {
        $result = new PaymentResult(
            success: true,
            orderId: 'order-01',
            paymentIntentId: 'pi_3N4x5y6z',
            requiresRedirect: false,
            redirectUrl: null,
        );

        self::assertTrue($result->success);
        self::assertSame('pi_3N4x5y6z', $result->paymentIntentId);
        self::assertFalse($result->requiresRedirect);
    }

    #[Test]
    public function paymentResultRequiresRedirect(): void
    {
        $result = new PaymentResult(
            success: false,
            orderId: 'order-02',
            paymentIntentId: 'pi_9A8b7C6d',
            requiresRedirect: true,
            redirectUrl: 'https://checkout.stripe.test/3ds/pi_9A8b7C6d',
        );

        self::assertFalse($result->success);
        self::assertTrue($result->requiresRedirect);
        self::assertNotNull($result->redirectUrl);
    }

    // -- PaymentStatus --------------------------------------------------------

    #[Test]
    public function paymentStatusIsPaidOnlyForPaidCase(): void
    {
        self::assertTrue(PaymentStatus::Paid->isPaid());
        self::assertFalse(PaymentStatus::Pending->isPaid());
        self::assertFalse(PaymentStatus::Failed->isPaid());
        self::assertFalse(PaymentStatus::Refunded->isPaid());
        self::assertFalse(PaymentStatus::PartiallyRefunded->isPaid());
    }

    #[Test]
    public function paymentStatusLabels(): void
    {
        self::assertSame('Pending', PaymentStatus::Pending->label());
        self::assertSame('Paid', PaymentStatus::Paid->label());
        self::assertSame('Failed', PaymentStatus::Failed->label());
        self::assertSame('Refunded', PaymentStatus::Refunded->label());
        self::assertSame('Partially Refunded', PaymentStatus::PartiallyRefunded->label());
    }

    // -- ProductAttribute -----------------------------------------------------

    #[Test]
    public function productAttributeConstructor(): void
    {
        $attr = new ProductAttribute(
            id: '0194d4e0-aaaa-7000-bbbb-000000000010',
            productId: '0194d4e0-aaaa-7000-bbbb-000000000011',
            attributeKey: 'size',
            allowedValues: ['S', 'M', 'L', 'XL'],
            translations: [
                'de' => ['S' => 'Klein', 'M' => 'Mittel', 'L' => 'Groß', 'XL' => 'Sehr Groß'],
            ],
        );

        self::assertSame('size', $attr->attributeKey);
        self::assertCount(4, $attr->allowedValues);
        self::assertSame('Klein', $attr->translations['de']['S']);
    }

    // -- ProductTranslation ---------------------------------------------------

    #[Test]
    public function productTranslationConstructor(): void
    {
        $trans = new ProductTranslation(
            id: '0194d4e0-cccc-7000-dddd-000000000020',
            productId: '0194d4e0-cccc-7000-dddd-000000000021',
            locale: 'fr',
            name: 'Widget Premium',
            description: 'Un widget de haute qualite pour les professionnels exigeants.',
            slug: 'widget-premium',
        );

        self::assertSame('fr', $trans->locale);
        self::assertSame('Widget Premium', $trans->name);
        self::assertSame('widget-premium', $trans->slug);
    }

    // -- PromotionType --------------------------------------------------------

    #[Test]
    public function promotionTypeLabels(): void
    {
        self::assertSame('Percentage Off', PromotionType::PercentageOff->label());
        self::assertSame('Fixed Amount Off', PromotionType::FixedAmountOff->label());
        self::assertSame('Free Shipping', PromotionType::FreeShipping->label());
        self::assertSame('Buy X Get Y', PromotionType::BuyXGetY->label());
    }

    // -- PromotionValidationResult --------------------------------------------

    #[Test]
    public function promotionValidationResultValid(): void
    {
        $result = new PromotionValidationResult(
            isValid: true,
            promotion: null,
            errors: [],
        );

        self::assertTrue($result->isValid);
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function promotionValidationResultInvalid(): void
    {
        $result = new PromotionValidationResult(
            isValid: false,
            promotion: null,
            errors: ['Coupon has expired', 'Minimum order not met'],
        );

        self::assertFalse($result->isValid);
        self::assertCount(2, $result->errors);
    }

    // -- ShippingResult -------------------------------------------------------

    #[Test]
    public function shippingResultConstructor(): void
    {
        $result = new ShippingResult(
            amount: 1299,
            method: ShippingMethod::Express,
            estimatedDays: 2,
        );

        self::assertSame(1299, $result->amount);
        self::assertSame(ShippingMethod::Express, $result->method);
        self::assertSame(2, $result->estimatedDays);
    }

    #[Test]
    public function shippingResultDigitalNoEstimate(): void
    {
        $result = new ShippingResult(
            amount: 0,
            method: ShippingMethod::Digital,
            estimatedDays: null,
        );

        self::assertSame(0, $result->amount);
        self::assertNull($result->estimatedDays);
    }

    // -- TaxLineItem ----------------------------------------------------------

    #[Test]
    public function taxLineItemConstructor(): void
    {
        $item = new TaxLineItem(
            productId: 'prod-01',
            taxRate: 0.21,
            taxAmount: 420,
        );

        self::assertSame('prod-01', $item->productId);
        self::assertSame(0.21, $item->taxRate);
        self::assertSame(420, $item->taxAmount);
    }

    // -- TaxResult ------------------------------------------------------------

    #[Test]
    public function taxResultConstructor(): void
    {
        $items = [
            new TaxLineItem('prod-01', 0.21, 420),
            new TaxLineItem('prod-02', 0.09, 90),
        ];

        $result = new TaxResult(
            items: $items,
            totalTax: 510,
            reverseCharge: false,
        );

        self::assertCount(2, $result->items);
        self::assertSame(510, $result->totalTax);
        self::assertFalse($result->reverseCharge);
    }

    #[Test]
    public function taxResultWithReverseCharge(): void
    {
        $result = new TaxResult(
            items: [],
            totalTax: 0,
            reverseCharge: true,
        );

        self::assertTrue($result->reverseCharge);
        self::assertSame(0, $result->totalTax);
    }
}
