<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Gateway;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\PayconiqConfig;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\QrPaymentFormat;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Gateway\QrPaymentGateway;
use Pulsar\Support\QrCodeEncoder;

final class QrPaymentGatewayTest extends TestCase
{
    private QrPaymentGateway $gateway;

    protected function setUp(): void
    {
        $qrEncoder = new QrCodeEncoder();
        $payconiqConfig = PayconiqConfig::fromArray([
            'merchant_id' => 'merch_test',
            'api_key' => 'key_test',
        ]);

        $this->gateway = new QrPaymentGateway($qrEncoder, $payconiqConfig);
    }

    #[Test]
    public function generateEpcQrProducesValidSvg(): void
    {
        $amount = Money::of(2500, Currency::EUR);

        $result = $this->gateway->generateEpcQr(
            amount: $amount,
            beneficiaryName: 'Test Company',
            iban: 'BE68 5390 0754 7034',
            bic: 'GKCCBEBB',
            reference: 'INV-2026-001',
        );

        self::assertSame(QrPaymentFormat::EpcQr, $result->format);
        self::assertSame(2500, $result->amount->amount);
        self::assertSame(Currency::EUR, $result->amount->currency);
        self::assertSame('INV-2026-001', $result->reference);
        self::assertStringContainsString('<svg', $result->svgContent);
        self::assertStringContainsString('</svg>', $result->svgContent);
    }

    #[Test]
    public function generateEpcQrPayloadContainsCorrectFields(): void
    {
        $amount = Money::of(1050, Currency::EUR);

        $result = $this->gateway->generateEpcQr(
            amount: $amount,
            beneficiaryName: 'My Shop',
            iban: 'NL91ABNA0417164300',
            bic: 'ABNANL2A',
            reference: 'REF-123',
            information: 'Order payment',
        );

        $payload = $result->payload;

        self::assertStringContainsString('BCD', $payload);
        self::assertStringContainsString('002', $payload);
        self::assertStringContainsString('SCT', $payload);
        self::assertStringContainsString('ABNANL2A', $payload);
        self::assertStringContainsString('My Shop', $payload);
        self::assertStringContainsString('NL91ABNA0417164300', $payload);
        self::assertStringContainsString('EUR10.50', $payload);
        self::assertStringContainsString('REF-123', $payload);
        self::assertStringContainsString('Order payment', $payload);
    }

    #[Test]
    public function generateEpcQrRejectsNonEurCurrency(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains('EPC QR codes only support EUR');

        $this->gateway->generateEpcQr(
            amount: Money::of(1000, Currency::USD),
            beneficiaryName: 'Test',
            iban: 'US123',
        );
    }

    #[Test]
    public function generateEpcQrStripsSpacesFromIban(): void
    {
        $result = $this->gateway->generateEpcQr(
            amount: Money::of(100, Currency::EUR),
            beneficiaryName: 'Test',
            iban: 'BE68 5390 0754 7034',
        );

        self::assertStringContainsString('BE68539007547034', $result->payload);
        self::assertStringNotContainsString('BE68 5390', $result->payload);
    }

    #[Test]
    public function generatePayconiqQrProducesValidSvg(): void
    {
        $amount = Money::of(1500, Currency::EUR);

        $result = $this->gateway->generatePayconiqQr(
            amount: $amount,
            paymentId: 'pay_123',
            reference: 'REF-456',
        );

        self::assertSame(QrPaymentFormat::Payconiq, $result->format);
        self::assertSame(1500, $result->amount->amount);
        self::assertSame('REF-456', $result->reference);
        self::assertStringContainsString('<svg', $result->svgContent);
        self::assertStringContainsString('payconiq.com/pay/2/merch_test/pay_123', $result->payload);
    }

    #[Test]
    public function generatePayconiqQrFailsWithoutMerchantId(): void
    {
        $gateway = new QrPaymentGateway(
            new QrCodeEncoder(),
            PayconiqConfig::fromArray([]),
        );

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains('merchant ID');

        $gateway->generatePayconiqQr(Money::of(1000, Currency::EUR), 'pay_id');
    }

    #[Test]
    public function generatePaymentLinkQrEncodesUrl(): void
    {
        $amount = Money::of(9900, Currency::EUR);

        $result = $this->gateway->generatePaymentLinkQr(
            amount: $amount,
            paymentUrl: 'https://pay.example.com/checkout/abc',
            reference: 'ORD-789',
        );

        self::assertSame(QrPaymentFormat::PaymentLink, $result->format);
        self::assertSame('https://pay.example.com/checkout/abc', $result->payload);
        self::assertSame(9900, $result->amount->amount);
        self::assertSame('ORD-789', $result->reference);
        self::assertStringContainsString('<svg', $result->svgContent);
    }

    #[Test]
    public function generatePaymentLinkQrAcceptsAnyCurrency(): void
    {
        $result = $this->gateway->generatePaymentLinkQr(
            amount: Money::of(5000, Currency::GBP),
            paymentUrl: 'https://pay.example.com/gbp',
        );

        self::assertSame(Currency::GBP, $result->amount->currency);
        self::assertStringContainsString('<svg', $result->svgContent);
    }

    #[Test]
    public function epcQrWithOptionalBicOmitted(): void
    {
        $result = $this->gateway->generateEpcQr(
            amount: Money::of(500, Currency::EUR),
            beneficiaryName: 'No BIC Shop',
            iban: 'DE89370400440532013000',
        );

        $lines = explode("\n", $result->payload);

        // Line index 4 is BIC; should be empty
        self::assertSame('', $lines[4]);
        self::assertSame('No BIC Shop', $lines[5]);
    }
}
