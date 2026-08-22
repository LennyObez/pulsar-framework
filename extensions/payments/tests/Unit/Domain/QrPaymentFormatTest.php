<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\QrPaymentFormat;

final class QrPaymentFormatTest extends TestCase
{
    #[Test]
    #[DataProvider('formatProvider')]
    public function formatValuesMapCorrectly(QrPaymentFormat $format, string $expectedValue): void
    {
        self::assertSame($expectedValue, $format->value);
    }

    /**
     * @return iterable<string, array{QrPaymentFormat, string}>
     */
    public static function formatProvider(): iterable
    {
        yield 'EPC QR' => [QrPaymentFormat::EpcQr, 'epc_qr'];
        yield 'Payconiq' => [QrPaymentFormat::Payconiq, 'payconiq'];
        yield 'Payment Link' => [QrPaymentFormat::PaymentLink, 'payment_link'];
    }

    #[Test]
    public function fromStringResolvesValidValues(): void
    {
        self::assertSame(QrPaymentFormat::EpcQr, QrPaymentFormat::from('epc_qr'));
        self::assertSame(QrPaymentFormat::Payconiq, QrPaymentFormat::from('payconiq'));
        self::assertSame(QrPaymentFormat::PaymentLink, QrPaymentFormat::from('payment_link'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(QrPaymentFormat::tryFrom('nonexistent'));
    }

    #[Test]
    public function casesReturnsAllFormats(): void
    {
        $cases = QrPaymentFormat::cases();

        self::assertCount(3, $cases);
    }
}
