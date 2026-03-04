<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Immutable QR payment data container.
 *
 * Holds the encoded payload and SVG content for a QR code payment.
 */
#[Api(since: '1.0.0')]
final readonly class QrPaymentData
{
    public function __construct(
        public QrPaymentFormat $format,
        public string $payload,
        public string $svgContent,
        public Money $amount,
        public string $reference,
    ) {}
}
