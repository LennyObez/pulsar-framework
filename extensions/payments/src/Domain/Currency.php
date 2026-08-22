<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use Pulsar\Api\Api;

/**
 * ISO 4217 currency codes with minor-unit digit counts.
 * @api
 */
#[Api(since: '1.0.0')]
enum Currency: string
{
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case JPY = 'JPY';
    case CHF = 'CHF';
    case CAD = 'CAD';
    case AUD = 'AUD';
    case NZD = 'NZD';
    case SEK = 'SEK';
    case NOK = 'NOK';
    case DKK = 'DKK';
    case SGD = 'SGD';
    case HKD = 'HKD';
    case BRL = 'BRL';
    case INR = 'INR';
    case MXN = 'MXN';
    case ZAR = 'ZAR';
    case PLN = 'PLN';
    case CZK = 'CZK';
    case HUF = 'HUF';
    case BHD = 'BHD';
    case KWD = 'KWD';
    case OMR = 'OMR';

    /**
     * Number of minor-unit digits for this currency.
     *
     * Most currencies use 2 (cents). JPY/HUF use 0. BHD/KWD/OMR use 3.
     */
    public function minorDigits(): int
    {
        return match ($this) {
            self::JPY, self::HUF => 0,
            self::BHD, self::KWD, self::OMR => 3,
            default => 2,
        };
    }

    /**
     * Currency symbol for display purposes.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::USD, self::CAD, self::AUD, self::NZD, self::SGD, self::HKD, self::MXN => '$',
            self::EUR => '€',
            self::GBP => '£',
            self::JPY => '¥',
            self::CHF => 'CHF',
            self::SEK, self::NOK, self::DKK => 'kr',
            self::BRL => 'R$',
            self::INR => '₹',
            self::ZAR => 'R',
            self::PLN => 'zł',
            self::CZK => 'Kč',
            self::HUF => 'Ft',
            self::BHD, self::KWD, self::OMR => $this->value,
        };
    }
}
