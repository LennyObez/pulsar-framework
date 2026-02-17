<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\PaymentMethod;

final class PaymentMethodEuTest extends TestCase
{
    #[Test]
    #[DataProvider('euMethodProvider')]
    public function euPaymentMethodsExistWithCorrectValues(string $expectedValue): void
    {
        $method = PaymentMethod::from($expectedValue);

        self::assertSame($expectedValue, $method->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function euMethodProvider(): iterable
    {
        yield 'Bancontact' => ['bancontact'];
        yield 'iDEAL' => ['ideal'];
        yield 'Klarna Pay Later' => ['klarna_pay_later'];
        yield 'Klarna Pay Now' => ['klarna_pay_now'];
        yield 'Klarna Slice It' => ['klarna_slice_it'];
        yield 'Payconiq' => ['payconiq'];
        yield 'EPC QR' => ['epc_qr'];
    }

    #[Test]
    #[DataProvider('scaRequiredProvider')]
    public function scaRequirementsCorrectForEuMethods(PaymentMethod $method, bool $expectedSca): void
    {
        self::assertSame($expectedSca, $method->requiresSca());
    }

    /**
     * @return iterable<string, array{PaymentMethod, bool}>
     */
    public static function scaRequiredProvider(): iterable
    {
        yield 'Bancontact requires SCA' => [PaymentMethod::Bancontact, true];
        yield 'iDEAL requires SCA' => [PaymentMethod::Ideal, true];
        yield 'Klarna Pay Later does not require SCA' => [PaymentMethod::KlarnaPayLater, false];
        yield 'Klarna Pay Now does not require SCA' => [PaymentMethod::KlarnaPayNow, false];
        yield 'Klarna Slice It does not require SCA' => [PaymentMethod::KlarnaSliceIt, false];
        yield 'Payconiq does not require SCA' => [PaymentMethod::Payconiq, false];
        yield 'EPC QR does not require SCA' => [PaymentMethod::EpcQr, false];
    }

    #[Test]
    public function allPaymentMethodCasesExist(): void
    {
        $allCases = PaymentMethod::cases();

        // Original 7 + 7 new EU methods = 14 total
        self::assertCount(14, $allCases);
    }

    #[Test]
    public function originalMethodsStillWork(): void
    {
        // Verify backward compatibility
        self::assertSame('card', PaymentMethod::Card->value);
        self::assertSame('sepa', PaymentMethod::Sepa->value);
        self::assertSame('paypal', PaymentMethod::PayPal->value);
        self::assertTrue(PaymentMethod::Card->requiresSca());
        self::assertFalse(PaymentMethod::PayPal->requiresSca());
    }
}
