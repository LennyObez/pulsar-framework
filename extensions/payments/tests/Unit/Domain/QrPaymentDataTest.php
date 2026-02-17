<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\QrPaymentData;
use Pulsar\Extension\Payments\Domain\QrPaymentFormat;

final class QrPaymentDataTest extends TestCase
{
    #[Test]
    public function constructorPreservesAllFields(): void
    {
        $amount = Money::of(2500, Currency::EUR);

        $data = new QrPaymentData(
            format: QrPaymentFormat::EpcQr,
            payload: 'BCD\n002\n1\nSCT',
            svgContent: '<svg>test</svg>',
            amount: $amount,
            reference: 'INV-001',
        );

        self::assertSame(QrPaymentFormat::EpcQr, $data->format);
        self::assertSame('BCD\n002\n1\nSCT', $data->payload);
        self::assertSame('<svg>test</svg>', $data->svgContent);
        self::assertSame(2500, $data->amount->amount);
        self::assertSame(Currency::EUR, $data->amount->currency);
        self::assertSame('INV-001', $data->reference);
    }

    #[Test]
    public function emptyReferenceAllowed(): void
    {
        $data = new QrPaymentData(
            format: QrPaymentFormat::PaymentLink,
            payload: 'https://pay.example.com',
            svgContent: '<svg/>',
            amount: Money::of(100, Currency::USD),
            reference: '',
        );

        self::assertSame('', $data->reference);
    }
}
