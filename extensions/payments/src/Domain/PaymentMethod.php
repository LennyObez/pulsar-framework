<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * Supported payment method types.
 * @api
 */
#[Api(since: '1.0.0')]
enum PaymentMethod: string
{
    case Card = 'card';
    case Sepa = 'sepa';
    case PayPal = 'paypal';
    case BankTransfer = 'bank_transfer';
    case ApplePay = 'apple_pay';
    case GooglePay = 'google_pay';
    case MobileInApp = 'mobile_in_app';
    case Bancontact = 'bancontact';
    case Ideal = 'ideal';
    case KlarnaPayLater = 'klarna_pay_later';
    case KlarnaPayNow = 'klarna_pay_now';
    case KlarnaSliceIt = 'klarna_slice_it';
    case Payconiq = 'payconiq';
    case EpcQr = 'epc_qr';

    /**
     * Whether this method requires Strong Customer Authentication (PSD2 SCA).
     */
    public function requiresSca(): bool
    {
        return match ($this) {
            self::Card, self::Sepa, self::ApplePay, self::GooglePay,
            self::Bancontact, self::Ideal => true,
            self::PayPal, self::BankTransfer, self::MobileInApp,
            self::KlarnaPayLater, self::KlarnaPayNow, self::KlarnaSliceIt,
            self::Payconiq, self::EpcQr => false,
        };
    }
}
