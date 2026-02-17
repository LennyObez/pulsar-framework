<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\BillingPortal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\BillingPortal\PaymentMethodSummary;

final class PaymentMethodSummaryTest extends TestCase
{
    #[Test]
    public function isExpiredReturnsTrueForPastDate(): void
    {
        $method = new PaymentMethodSummary(
            id: 'pm-1',
            type: 'card',
            last4: '4242',
            brand: 'visa',
            expiryMonth: 1,
            expiryYear: 2020,
        );

        self::assertTrue($method->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseForFutureDate(): void
    {
        $method = new PaymentMethodSummary(
            id: 'pm-2',
            type: 'card',
            last4: '1234',
            brand: 'mastercard',
            expiryMonth: 12,
            expiryYear: 2099,
        );

        self::assertFalse($method->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseWhenNoExpirySet(): void
    {
        $method = new PaymentMethodSummary(
            id: 'pm-3',
            type: 'paypal',
            last4: '9999',
        );

        self::assertFalse($method->isExpired());
    }

    #[Test]
    public function storesAllProperties(): void
    {
        $method = new PaymentMethodSummary(
            id: 'pm-4',
            type: 'card',
            last4: '4242',
            brand: 'visa',
            expiryMonth: 3,
            expiryYear: 2028,
            isDefault: true,
        );

        self::assertSame('pm-4', $method->id);
        self::assertSame('card', $method->type);
        self::assertSame('4242', $method->last4);
        self::assertSame('visa', $method->brand);
        self::assertSame(3, $method->expiryMonth);
        self::assertSame(2028, $method->expiryYear);
        self::assertTrue($method->isDefault);
    }

    #[Test]
    public function defaultsToNotDefault(): void
    {
        $method = new PaymentMethodSummary(
            id: 'pm-5',
            type: 'sepa',
            last4: '0001',
        );

        self::assertFalse($method->isDefault);
        self::assertSame('', $method->brand);
    }
}
