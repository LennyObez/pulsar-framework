<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * QR code payment format standards.
 * @api
 */
#[Api(since: '1.0.0')]
enum QrPaymentFormat: string
{
    /** EPC QR code (European Payments Council standard, SCT Inst). */
    case EpcQr = 'epc_qr';

    /** Payconiq QR code (Belgium/Luxembourg/Netherlands). */
    case Payconiq = 'payconiq';

    /** Generic payment link encoded as QR. */
    case PaymentLink = 'payment_link';
}
